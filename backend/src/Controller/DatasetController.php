<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dataset;
use App\Entity\User;
use App\Presentation\DatasetPresenter;
use App\Repository\DatasetRepository;
use App\Repository\DatasetVersionRepository;
use App\Service\DatasetIngestionService;
use App\Storage\DatasetStorageInterface;
use App\Upload\UploadRejectedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final class DatasetController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly DatasetIngestionService $ingestionService,
        private readonly DatasetRepository $datasetRepository,
        private readonly DatasetVersionRepository $versionRepository,
        private readonly DatasetStorageInterface $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/datasets', name: 'api_datasets_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('file');
        $name = $request->request->get('name');
        $description = $request->request->get('description');

        try {
            $result = $this->ingestionService->ingest(
                $user,
                $file,
                \is_string($name) ? $name : null,
                \is_string($description) ? $description : null,
            );
        } catch (UploadRejectedException $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], $e->httpStatusCode);
        }

        $payload = DatasetPresenter::present($result->dataset, [$result->version]);
        if ($result->duplicateOf !== null) {
            $payload['possibleDuplicateOfDatasetId'] = (string) $result->duplicateOf->getId();
        }

        return new JsonResponse($payload, 201);
    }

    #[Route('/api/datasets', name: 'api_datasets_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_PAGE_SIZE, max(1, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));

        $datasets = $this->datasetRepository->findPageForOwner($user, $page, $limit);
        $total = $this->datasetRepository->countForOwner($user);

        $items = array_map(
            fn (Dataset $dataset) => DatasetPresenter::present($dataset, $this->versionRepository->findByDataset($dataset)),
            $datasets,
        );

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/api/datasets/{id}', name: 'api_datasets_show', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $dataset = $this->findOwnedDataset($id, $user);
        $versions = $this->versionRepository->findByDataset($dataset);

        return new JsonResponse(DatasetPresenter::present($dataset, $versions));
    }

    #[Route('/api/datasets/{id}', name: 'api_datasets_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user): Response
    {
        $dataset = $this->findOwnedDataset($id, $user);
        $versions = $this->versionRepository->findByDataset($dataset);

        foreach ($versions as $version) {
            $this->storage->delete($version->getStoragePath());
            $this->em->remove($version);
        }

        $this->em->remove($dataset);
        $this->em->flush();

        return new Response(null, 204);
    }

    private function findOwnedDataset(string $id, User $user): Dataset
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Dataset not found.');
        }

        $dataset = $this->datasetRepository->find(Uuid::fromString($id));
        if ($dataset === null) {
            throw new NotFoundHttpException('Dataset not found.');
        }

        if (!$dataset->getOwner()->getId()->equals($user->getId())) {
            throw new AccessDeniedHttpException('You do not have access to this dataset.');
        }

        return $dataset;
    }
}
