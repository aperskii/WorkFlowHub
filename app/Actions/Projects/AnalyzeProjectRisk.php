<?php

namespace App\Actions\Projects;

use App\Enums\TaskPriority;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask Anthropic's Messages API whether a project looks at risk, and why.
 *
 * The assessment is derived from aggregate counts only. Task titles,
 * descriptions, member names, and the project description are deliberately never
 * sent; see ADR-011 for the reasoning and for what a future slice may add.
 */
class AnalyzeProjectRisk
{
    /**
     * Anthropic's Messages endpoint and the API version it is pinned to.
     */
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2023-06-01';

    /**
     * How far ahead a due date still counts as imminent.
     */
    public const int DUE_SOON_DAYS = 7;

    /**
     * An assessment is a short paragraph, so the ceiling is low. It bounds the
     * cost of a single call and keeps a runaway response from hanging the page.
     */
    private const int MAX_TOKENS = 400;

    /**
     * How long to wait for the connection and for the response as a whole.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Produce a plain language risk assessment for the project.
     *
     * Returns null when no assessment could be produced — no key configured, the
     * API unreachable, an error status, or a response that did not contain
     * usable text. Every one of those is logged and none of them reach the user,
     * who is told only that the analysis could not be run.
     */
    public function handle(Project $project): ?string
    {
        $apiKey = config('services.anthropic.key');

        if (! is_string($apiKey) || $apiKey === '') {
            Log::warning('Project risk analysis skipped: no Anthropic API key configured.', [
                'project_id' => $project->id,
            ]);

            return null;
        }

        $model = config('services.anthropic.model');
        $timeout = config('services.anthropic.timeout');

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(is_int($timeout) ? $timeout : 10)
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, [
                    'model' => is_string($model) ? $model : 'claude-haiku-4-5',
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $this->systemPrompt(),
                    'messages' => [[
                        'role' => 'user',
                        'content' => json_encode($this->snapshotFor($project), JSON_THROW_ON_ERROR),
                    ]],
                ]);
        } catch (Throwable $exception) {
            // Connection refused, DNS failure, timeout, or an unserializable
            // snapshot. The message is ours; it is never shown to the user.
            Log::warning('Project risk analysis could not reach the Anthropic API.', [
                'project_id' => $project->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Project risk analysis was rejected by the Anthropic API.', [
                'project_id' => $project->id,
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_message' => $response->json('error.message'),
            ]);

            return null;
        }

        return $this->extractAssessment($project, $response->json());
    }

    /**
     * Build the aggregate facts describing the project's health.
     *
     * This array is the complete payload sent to Anthropic. Nothing else about
     * the project, its tasks, or its people leaves the application, so the whole
     * data minimization decision is auditable by reading this one method.
     *
     * @return array{
     *     project_name: string,
     *     project_status: string,
     *     project_age_days: int,
     *     total_tasks: int,
     *     completed_tasks: int,
     *     open_tasks: int,
     *     completion_percentage: int,
     *     overdue_tasks: int,
     *     unassigned_open_tasks: int,
     *     high_priority_due_within_7_days: int,
     * }
     */
    public function snapshotFor(Project $project): array
    {
        $total = $project->tasks()->count();
        $completed = $project->tasks()->completed()->count();

        return [
            'project_name' => $project->name,
            'project_status' => $project->status->value,
            'project_age_days' => $this->ageInDays($project),
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'open_tasks' => $total - $completed,
            'completion_percentage' => $total === 0 ? 0 : (int) round($completed / $total * 100),
            'overdue_tasks' => $project->tasks()->overdue()->count(),
            'unassigned_open_tasks' => $project->tasks()->open()->unassigned()->count(),
            'high_priority_due_within_7_days' => $project->tasks()
                ->dueSoon(self::DUE_SOON_DAYS)
                ->whereIn('priority', [TaskPriority::High, TaskPriority::Urgent])
                ->count(),
        ];
    }

    /**
     * Get the number of whole days since the project was created.
     */
    private function ageInDays(Project $project): int
    {
        if ($project->created_at === null) {
            return 0;
        }

        return (int) $project->created_at->copy()->startOfDay()->diffInDays(today());
    }

    /**
     * Get the instructions that shape the assessment.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You assess delivery risk for software and agency projects.

            The user message is a JSON object of aggregate statistics about one
            project. It is all you get: you cannot see the tasks, the people, or
            the project description, so never claim to know what the work is or
            who is doing it, and never ask for more data.

            Reply with two to four sentences of plain language for a project
            manager. State whether the project looks at risk, then give the
            specific numbers that led you there. If the figures look healthy, say
            so plainly rather than inventing a concern. If there are no tasks at
            all, say the project has nothing to assess yet.

            Write prose only. No headings, no bullet points, no JSON, no preamble
            such as "Based on the data". Do not restate the whole object back.
            PROMPT;
    }

    /**
     * Pull the assessment text out of a successful Messages API response.
     */
    private function extractAssessment(Project $project, mixed $payload): ?string
    {
        if (is_array($payload) && ($payload['stop_reason'] ?? null) === 'refusal') {
            Log::warning('Project risk analysis was declined by the model.', [
                'project_id' => $project->id,
            ]);

            return null;
        }

        $blocks = is_array($payload) ? ($payload['content'] ?? null) : null;

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                    continue;
                }

                $text = $block['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        Log::warning('Project risk analysis received a response with no usable text.', [
            'project_id' => $project->id,
        ]);

        return null;
    }
}
