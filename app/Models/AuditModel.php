<?php

/**
 * AuditModel — Operasi pencatatan log audit (append-only).
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Append-only (HANYA INSERT, DILARANG UPDATE / DELETE).
 * - Semua query menggunakan Prepared Statements PDO.
 * - Mencatat setiap interaksi prompt, respon LLM, keputusan, dan mutasi.
 */
class AuditModel
{
    private PDO $pdo;

    private const VALID_DECISIONS = [
        'EXECUTED',
        'REJECTED',
        'CLARIFIED',
        'CANCELLED',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mencatat satu entri ke tabel audit_logs.
     *
     * @param array{
     *     user_id?: int|null,
     *     raw_prompt: string,
     *     llm_json_response?: string|array|null,
     *     decision: 'EXECUTED'|'REJECTED'|'CLARIFIED'|'CANCELLED',
     *     reason?: string|null,
     *     movement_id?: int|null,
     *     model_name?: string|null,
     *     latency_ms?: int|null
     * } $data
     * @return int ID baris yang baru dimasukkan (lastInsertId)
     * @throws InvalidArgumentException Jika format keputusan tidak valid
     */
    public function log(array $data): int
    {
        $decision = strtoupper(trim((string) ($data['decision'] ?? '')));
        if (!in_array($decision, self::VALID_DECISIONS, true)) {
            throw new InvalidArgumentException("Keputusan audit tidak valid: '{$decision}'");
        }

        $rawPrompt = (string) ($data['raw_prompt'] ?? '');

        // Format llm_json_response: jika array, encode ke JSON string
        $llmJsonResponse = $data['llm_json_response'] ?? null;
        if (is_array($llmJsonResponse)) {
            $llmJsonResponse = json_encode($llmJsonResponse, JSON_UNESCAPED_UNICODE);
        } elseif ($llmJsonResponse !== null) {
            $llmJsonResponse = (string) $llmJsonResponse;
        }

        $userId = isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null;
        $reason = isset($data['reason']) && $data['reason'] !== null ? mb_substr((string) $data['reason'], 0, 255) : null;
        $movementId = isset($data['movement_id']) && $data['movement_id'] !== null ? (int) $data['movement_id'] : null;
        $modelName = isset($data['model_name']) && $data['model_name'] !== null ? mb_substr((string) $data['model_name'], 0, 100) : null;
        $latencyMs = isset($data['latency_ms']) && $data['latency_ms'] !== null ? (int) $data['latency_ms'] : null;

        $sql = 'INSERT INTO audit_logs (
                    user_id,
                    raw_prompt,
                    llm_json_response,
                    decision,
                    reason,
                    movement_id,
                    model_name,
                    latency_ms
                ) VALUES (
                    :user_id,
                    :raw_prompt,
                    :llm_json_response,
                    :decision,
                    :reason,
                    :movement_id,
                    :model_name,
                    :latency_ms
                )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id'           => $userId,
            ':raw_prompt'        => $rawPrompt,
            ':llm_json_response' => $llmJsonResponse,
            ':decision'          => $decision,
            ':reason'            => $reason,
            ':movement_id'       => $movementId,
            ':model_name'        => $modelName,
            ':latency_ms'        => $latencyMs,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
