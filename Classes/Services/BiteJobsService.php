<?php

declare(strict_types=1);

namespace FGTCLB\AcademicBiteJobs\Services;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final class BiteJobsService
{
    /**
     * @var array<string|array<string, mixed>, mixed>|null $responseBody
     * @todo Response state on a service class ? A really really bad idea.
     */
    protected $responseBody;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * custom_field1 is a custom Field set by select type
     * @return string[]
     */
    public function fetchBiteJobs(?ServerRequestInterface $request = null): array
    {
        $flexformTool = GeneralUtility::makeInstance(FlexFormService::class);

        /** @var array<string, mixed> $contentElementData */
        $contentElementData = $this->getCurrentContentObjectRenderer($request ?? $GLOBALS['TYPO3_REQUEST'] ?? new ServerRequest())?->data ?? [];
        $settings = $flexformTool->convertFlexFormContentToArray((string)($contentElementData['pi_flexform'] ?? ''));

        $jobsSettings = $settings['settings']['jobs'];

        $parameters = [
            'apikey' => $jobsSettings['jobListingKey'],
            'channel' => 0,
            'columns' => [
                'title',
                'description',
                'jobSite'
            ],
            'language' => [
                'filter' => [
                    'enable' => true,
                    'value' => $jobsSettings['language']
                ]
            ],
            'order' => $jobsSettings['sortingDirection'],
            'sort' => $jobsSettings['sortBy'],
        ];

        if ($jobsSettings['custom_field1'] !== 'all') {
            $parameters['custom_field1'] = [
                'filter' => [
                    'enable' => true,
                    'value' => $jobsSettings['custom_field1']
                ]
            ];
        }

        $jobs = [];

        $searchUrl = 'https://jobs.b-ite.com/adsapi/jobads?' . http_build_query($parameters);

        try {
            $response = $this->requestFactory->request($searchUrl);

            $this->responseBody = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error while fetching jobs from Bite API: %s',
                $e->getMessage()
            ));
        }
        if (!empty($this->responseBody['advertisements'])) {
            // We need to map the custom fields to the jobs so we have the labels instead of the values in the frontend
            $jobs = $this->mapFieldsToJobs($this->responseBody['advertisements'], 'custom_field1');
            $jobs = $this->groupByRelations($jobs);
        }

        // Set Job Limit if setting is not empty
        if (!empty($jobsSettings['limit'])) {
            $jobs = array_slice($jobs, 0, (int)$jobsSettings['limit']);
        }

        return $jobs;
    }

    /**
     * @return string[]
     */
    public function findCustomJobRelations(): array
    {
        $fields = [];

        if (isset($this->responseBody['fields']['custom_field1']['options'])) {
            foreach ($this->responseBody['fields']['custom_field1']['options'] as $value) {
                $fields[$value['id']] = $value['label'];
            }
        }
        return $fields;
    }

    /**
     * As the Bite API has custom fields, some of thoses have labels and some just values.
     * To be able to map the values to the labels, we need to find the labels first in the Fields.
     * @return string[]
     */
    public function findCustomBiteFieldLabelsFromOptions(string $item): array
    {
        $fields = [];

        if (isset($this->responseBody['fields'][$item]['options'])) {
            foreach ($this->responseBody['fields'][$item]['options'] as $key => $value) {
                $fields[$value['id']] = $value['label'];
            }
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $jobs
     * @return string[]
     */
    public function groupByRelations(array $jobs): array
    {
        $grouped = [];
        $biteRelations = $this->findCustomJobRelations();

        foreach ($jobs as $job) {
            foreach ($biteRelations as $relationKey => $relationValue) {
                if ($relationKey === $job['custom_field1']) {
                    $job['relationName'] = $relationValue;
                    $grouped[] = $job;
                }
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $jobs
     * @return string[]
     */
    public function mapFieldsToJobs(array $jobs, string $customFieldName): array
    {
        $grouped = [];
        $field = $this->findCustomBiteFieldLabelsFromOptions($customFieldName);

        foreach ($jobs as $job) {
            if (array_key_exists($job[$customFieldName], $field)) {
                $job[$customFieldName] = $field[$job[$customFieldName]];
            }

            $grouped[] = $job;
        }

        return $grouped;
    }

    private function getCurrentContentObjectRenderer(ServerRequestInterface $request): ?ContentObjectRenderer
    {
        return $request->getAttribute('currentContentObject');
    }
}
