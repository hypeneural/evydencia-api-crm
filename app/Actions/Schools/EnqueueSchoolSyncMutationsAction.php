<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class EnqueueSchoolSyncMutationsAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/sync/mutations",
     *     tags={"Escolas"},
     *     summary="Enfileira mutacoes offline",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"mutations"},
     *             @OA\Property(
     *                 property="mutations",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     required={"client_id","tipo","payload"},
     *                     @OA\Property(property="client_id", type="string"),
     *                     @OA\Property(property="tipo", type="string"),
     *                     @OA\Property(property="payload", type="object"),
     *                     @OA\Property(property="versao_row", type="integer", nullable=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Mutacoes enfileiradas"),
     *     @OA\Response(response=422, description="Dados invalidos"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $payload = [];
        }

        $mutations = $payload['mutations'] ?? [];
        if (!is_array($mutations)) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'mutations', 'message' => 'Deve ser uma lista.'],
            ]);
        }

        try {
            $normalized = $this->normalizeMutations($payload, $mutations);
            if ($normalized['errors'] !== []) {
                return $this->responder->validationError($response, $traceId, $normalized['errors']);
            }

            $result = $this->service->enqueueMutations($normalized['mutations']);

            foreach ($result as $index => &$row) {
                if (isset($normalized['client_mutation_ids'][$index])) {
                    $row['client_mutation_id'] = $normalized['client_mutation_ids'][$index];
                }
                if (isset($normalized['escola_ids'][$index])) {
                    $row['escola_id'] = $normalized['escola_ids'][$index];
                }
            }
            unset($row);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel registrar as mutacoes.');
        }

        return $this->responder->success(
            $response,
            ['mutations' => $result],
            [
                'count' => count($result),
                'source' => 'database',
            ],
            [
                'self' => (string) $request->getUri(),
            ],
            $traceId
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $mutations
     * @return array{
     *     mutations: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, string>>,
     *     client_mutation_ids: array<int, string>,
     *     escola_ids: array<int, int>
     * }
     */
    private function normalizeMutations(array $payload, array $mutations): array
    {
        $globalClientId = isset($payload['client_id']) ? (string) $payload['client_id'] : null;
        $normalized = [];
        $errors = [];
        $clientMutationIds = [];
        $escolaIds = [];

        foreach ($mutations as $index => $mutation) {
            if (!is_array($mutation)) {
                $errors[] = ['field' => sprintf('mutations[%d]', $index), 'message' => 'Mutacao deve ser objeto.'];
                continue;
            }

            $clientId = $mutation['client_id'] ?? $globalClientId;
            if (!is_string($clientId) || $clientId === '') {
                $errors[] = ['field' => sprintf('mutations[%d].client_id', $index), 'message' => 'client_id obrigatorio.'];
                continue;
            }

            $tipo = $mutation['tipo'] ?? $mutation['type'] ?? null;
            if (!is_string($tipo) || $tipo === '') {
                $errors[] = ['field' => sprintf('mutations[%d].tipo', $index), 'message' => 'tipo obrigatorio.'];
                continue;
            }

            $rawPayload = $mutation['payload'] ?? $mutation['updates'] ?? null;
            if ($rawPayload === null) {
                $errors[] = ['field' => sprintf('mutations[%d].payload', $index), 'message' => 'payload obrigatorio.'];
                continue;
            }

            if (!is_array($rawPayload)) {
                $errors[] = ['field' => sprintf('mutations[%d].payload', $index), 'message' => 'payload deve ser objeto.'];
                continue;
            }

            if (isset($mutation['escola_id']) && !isset($rawPayload['escola_id'])) {
                $rawPayload['escola_id'] = $mutation['escola_id'];
            }

            if (isset($mutation['client_mutation_id'])) {
                $rawPayload['client_mutation_id'] = (string) $mutation['client_mutation_id'];
                $clientMutationIds[$index] = (string) $mutation['client_mutation_id'];
            }

            if (isset($rawPayload['escola_id'])) {
                $escolaIds[$index] = (int) $rawPayload['escola_id'];
            } elseif (isset($mutation['escola_id'])) {
                $escolaIds[$index] = (int) $mutation['escola_id'];
            }

            $normalized[] = [
                'client_id' => $clientId,
                'tipo' => $tipo,
                'payload' => $rawPayload,
                'versao_row' => isset($mutation['versao_row']) ? (int) $mutation['versao_row'] : null,
            ];
        }

        return [
            'mutations' => $normalized,
            'errors' => $errors,
            'client_mutation_ids' => $clientMutationIds,
            'escola_ids' => $escolaIds,
        ];
    }
}
