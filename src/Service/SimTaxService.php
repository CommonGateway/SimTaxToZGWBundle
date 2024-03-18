<?php
/**
 * An example service for adding business logic to your class.
 *
 * @author  Wilco Louwerse <wilco@conduction.nl>, Barry Brands <barry@conduction.nl>, Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle\Service;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\MappingService;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use App\Entity\ObjectEntity;
use App\Entity\Entity;
use App\Event\ActionEvent;
use DateTime;
use DateInterval;
use CommonGateway\OpenBelastingBundle\Service\SyncAanslagenService;

class SimTaxService
{

    /**
     * The configuration of the current action.
     *
     * @var array
     */
    private array $configuration;

    /**
     * The data array from/for the current api call.
     *
     * @var array
     */
    private array $data;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SyncAanslagenService
     */
    private SyncAanslagenService $syncAanslagenService;

    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * The plugin name of this plugin.
     */
    private const PLUGIN_NAME = 'common-gateway/sim-tax-to-zgw-bundle';

    /**
     * The mapping references used in this service.
     */
    private const MAPPING_REFS = [
        "GetAanslagen"        => "https://dowr.simxml.nl/mapping/simxml.get.aanslagen.mapping.json",
        "GetAanslag"          => "https://dowr.simxml.nl/mapping/simxml.get.aanslag.mapping.json",
        "PostBezwaarRequest"  => "https://dowr.simxml.nl/mapping/simxml.post.bezwaar.request.mapping.json",
        "PostBezwaarResponse" => "https://dowr.simxml.nl/mapping/simxml.post.bezwaar.response.mapping.json",
    ];

    /**
     * The schema references used in this service.
     */
    private const SCHEMA_REFS = [
        "Aanslagbiljet"   => "https://openbelasting.nl/schemas/openblasting.aanslagbiljet.schema.json",
        "BezwaarAanvraag" => "https://openbelasting.nl/schemas/openblasting.bezwaaraanvraag.schema.json",
    ];


    /**
     * @param GatewayResourceService   $resourceService        The Gateway Resource Service.
     * @param CacheService             $cacheService           The CacheService
     * @param MappingService           $mappingService         The Mapping Service
     * @param SynchronizationService   $synchronizationService The Synchronization Service
     * @param EntityManagerInterface   $entityManager          The Entity Manager.
     * @param SyncAanslagenService     $syncAanslagenService   The Sync Aanslagen Service.
     * @param LoggerInterface          $pluginLogger           The plugin version of the logger interface.
     * @param EventDispatcherInterface $eventDispatcher        The EventDispatcherInterface.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        SynchronizationService $synchronizationService,
        EntityManagerInterface $entityManager,
        SyncAanslagenService $syncAanslagenService,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService        = $resourceService;
        $this->cacheService           = $cacheService;
        $this->mappingService         = $mappingService;
        $this->synchronizationService = $synchronizationService;
        $this->entityManager          = $entityManager;
        $this->syncAanslagenService   = $syncAanslagenService;
        $this->logger                 = $pluginLogger;
        $this->eventDispatcher        = $eventDispatcher;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * An example handler that is triggered by an action.
     *
     * @param array $data          The data array
     * @param array $configuration The configuration array
     *
     * @return array A handler must ALWAYS return an array
     */
    public function simTaxHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        $this->logger->info("SimTaxService -> simTaxHandler()");

        if (isset($this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht']['ns1:stuurgegevens']) === false
            && isset($this->data['body']['SOAP-ENV:Body']['ns2:kennisgevingsBericht']['ns1:stuurgegevens']) === false
        ) {
            $this->logger->error('No vraagBericht -> stuurgegevens OR kennisgevingsBericht -> stuurgegevens found in xml body, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'No vraagBericht -> stuurgegevens OR kennisgevingsBericht -> stuurgegevens found in xml body'], 400)];
        }

        $vraagBericht         = $this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht'] ?? null;
        $kennisgevingsBericht = $this->data['body']['SOAP-ENV:Body']['ns2:kennisgevingsBericht'] ?? null;
        $stuurGegevens        = ($vraagBericht['ns1:stuurgegevens'] ?? $kennisgevingsBericht['ns1:stuurgegevens']);

        $this->logger->info("BerichtSoort {$stuurGegevens['ns1:berichtsoort']} & entiteittype {$stuurGegevens['ns1:entiteittype']}");

        switch ($stuurGegevens['ns1:berichtsoort'].'-'.$stuurGegevens['ns1:entiteittype']) {
        case 'Lv01-BLJ':
            $response = $this->getAanslagen($vraagBericht);
            break;
        case 'Lv01-OPO':
            $response = $this->getAanslag($vraagBericht);
            break;
        case 'Lk01-BGB':
            $response = $this->createBezwaar($kennisgevingsBericht);
            break;
        default:
            $this->logger->warning('Unknown berichtsoort & entiteittype combination, returning bad request error');
            $response = $this->createResponse(['Error' => 'Unknown berichtsoort & entiteittype combination'], 400);
        }

        return ['response' => $response];

    }//end simTaxHandler()


    /**
     * Get aanslagen objects based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return Response
     */
    public function getAanslagen(array $vraagBericht): Response
    {
        $mapping = $this->resourceService->getMapping($this::MAPPING_REFS['GetAanslagen'], $this::PLUGIN_NAME);
        if ($mapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['GetAanslagen']}."], 501);
        }

        if (isset($vraagBericht['ns2:body']['ns2:BLJ']) === false) {
            return $this->createResponse(['Error' => "No ns2:BLJ found in ns2:body"], 400);
        }

        // Fail-safe in case only one ns2:BLJ is given
        if (isset($vraagBericht['ns2:body']['ns2:BLJ'][1]) === false) {
            $blj                                   = $vraagBericht['ns2:body']['ns2:BLJ'];
            $vraagBericht['ns2:body']['ns2:BLJ']   = [];
            $vraagBericht['ns2:body']['ns2:BLJ'][] = $blj;
        }

        $filter = $this->getAanslagenFilter($vraagBericht);

        // Old gateway filter
        // if (isset($filter['embedded.belastingplichtige.burgerservicenummer']) === false) {
        if (isset($filter['bsn']) === false) {
            return $this->createResponse(['Error' => "No bsn given."], 400);
        }

        // Sync aanslagen from openbelasting api with given bsn. (The old way didn't use the return value of this function)
        $aanslagen['results'] = $this->syncAanslagenService->fetchAndSyncAanslagen($filter['bsn'], $filter['belastingjaar-vanaf']);
        // Old filter
        // $this->syncAanslagenService->fetchAndSyncAanslagen($filter['embedded.belastingplichtige.burgerservicenummer']);
        // Make sure we order correctly
        // Old gateway filter
        // $filter['_order']['belastingJaar'] = 'desc';
        // Old way of getting aanslagen from the gateway itself
        // Then fetch synced aanslagen through cacheService.
        // $aanslagen = $this->cacheService->searchObjects(null, $filter, [$this::SCHEMA_REFS['Aanslagbiljet']]);
        // TODO: this is a temporary workaround at the request of SIM
        // This will set the Aanslag "bezwaarMogelijk" to false if one of it's "aanslagregels" has "bezwaarMogelijk" set to false.
        foreach ($aanslagen['results'] as $aanslag) {
            // Old gateway/not proxy version with embedded
            // if (isset($aanslag['embedded']['aanslagregels']) === false) {
            if (isset($aanslag['aanslagregels']) === false) {
                continue;
            }

            // Old gateway/not proxy version with embedded
            // foreach ($aanslag['embedded']['aanslagregels'] as $aanslagregel) {
            foreach ($aanslag['aanslagregels'] as $aanslagregel) {
                if ($aanslagregel['bezwaarMogelijk'] == false) {
                    $aanslag['bezwaarMogelijk'] = false;
                    break;
                }
            }
        }

        $aanslagen['vraagbericht'] = $vraagBericht;

        $responseContext = $this->mappingService->mapping($mapping, $aanslagen);

        return $this->createResponse($responseContext, 200);

    }//end getAanslagen()


    /**
     * Generate the correct filter for getting aanslag objects from MongoDB with the cacheService.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return array The filters we need to call cacheService->searchObjects with.
     */
    private function getAanslagenFilter(array $vraagBericht): array
    {
        $minYear = $maxYear = null;

        // Make sure ['ns2:body']['ns2:BLJ'] is always an array of items
        if (isset($vraagBericht['ns2:body']['ns2:BLJ'][1]) === false) {
            $BLJ = $vraagBericht['ns2:body']['ns2:BLJ'];
            unset($vraagBericht['ns2:body']['ns2:BLJ']);

            $vraagBericht['ns2:body']['ns2:BLJ'][] = $BLJ;
            $vraagBericht['ns2:body']['ns2:BLJ'][] = [];
        }

        foreach ($vraagBericht['ns2:body']['ns2:BLJ'] as $key => $blj) {
            if (isset($blj['ns2:BLJPRS']['ns2:PRS']['ns2:bsn-nummer']) === false) {
                continue;
            }

            if (isset($bsn) === false) {
                $bsn = $blj['ns2:BLJPRS']['ns2:PRS']['ns2:bsn-nummer'];
            }

            if (isset($bsn) === true
                && $bsn === $blj['ns2:BLJPRS']['ns2:PRS']['ns2:bsn-nummer']
                && isset($blj['ns2:extraElementen']['ns1:extraElement']['#'])
            ) {
                // First key should be the min year
                if ($key === array_key_first($vraagBericht['ns2:body']['ns2:BLJ'])) {
                    $minYear = $blj['ns2:extraElementen']['ns1:extraElement']['#'];
                }

                // Old gateway filter
                // Last key should be the max year
                // if ($key === array_key_last($vraagBericht['ns2:body']['ns2:BLJ'])) {
                // $maxYear = $blj['ns2:extraElementen']['ns1:extraElement']['#'];
                // }
            }
        }//end foreach

        if (isset($minYear) === true) {
            $filter['belastingjaar-vanaf'] = $minYear;
            // Old gateway filter
            // $filter = $this->getMinMaxYearFilter($vraagBericht, $minYear, $maxYear);
        } else {
            // Old warning log
            // $this->logger->warning('Could not find a minimal year for bsn: '.($bsn ?? '').' Using current & last year instead for getting Aanslagen');
            $filter = [];
        }

        // Old gateway filter, for now default value will be set in the OpenBelastingBundle
        // If we have no (min/max) belastingJaar filter in the request use this year and the last year for filtering instead.
        // if (isset($filter['belastingJaar']) === false) {
        // $dateTime                  = new DateTime();
        // $filter['belastingJaar'][] = $dateTime->format('Y');
        // $dateTime->add(DateInterval::createFromDateString('-1 year'));
        // $filter['belastingJaar'][] = $dateTime->format('Y');
        // }
        if (isset($bsn)) {
            $filter['bsn'] = $bsn;

            // Old gateway filter
            // $filter['embedded.belastingplichtige.burgerservicenummer'] = $bsn;
        }

        return $filter;

    }//end getAanslagenFilter()


    /**
     * Gets the correct min/max belastingJaar filter for getting aanslag objects from MongoDB with the cacheService.
     *
     * @param array       $vraagBericht The vraagBericht content from the body of the current request.
     * @param string      $minYear      The minYear we found from the vraagBericht content.
     * @param string|null $maxYear      The maxYear we found from the vraagBericht content or null.
     *
     * @return array The filter array with correct min/max belastingJaar filter.
     */
    private function getMinMaxYearFilter(array $vraagBericht, string $minYear, ?string $maxYear): array
    {
        $filter = [];

        // If no max year was given in the request, we default max year to the current year.
        if (isset($maxYear) === false || ($maxYear === $minYear && count($vraagBericht['ns2:body']['ns2:BLJ']) === 1)) {
            $maxYear = new DateTime();
            $maxYear = $maxYear->format('Y');
        }

        if ($minYear <= $maxYear) {
            // Now add all years to the belastingJaar filter.
            $year = $minYear;
            while ($year <= $maxYear) {
                $filter['belastingJaar'][] = (string) $year;
                $year++;
            }
        }

        return $filter;

    }//end getMinMaxYearFilter()


    /**
     * Get a single aanslag object based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return Response
     */
    public function getAanslag(array $vraagBericht): Response
    {
        $mapping = $this->resourceService->getMapping($this::MAPPING_REFS['GetAanslag'], $this::PLUGIN_NAME);
        if ($mapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['GetAanslag']}."], 501);
        }

        // (new) Get correct filters for getting all aanslagen
        $filter = $this->getAanslagenFilter($vraagBericht);

        // Old gateway filter
        // if (isset($filter['embedded.belastingplichtige.burgerservicenummer']) === false) {
        if (isset($filter['bsn']) === false) {
            // (new) We need to check if filter contains bsn
            return $this->createResponse(['Error' => "No bsn given."], 400);
        }

        // (new) get all aanslagen from source instead of syncing
        $aanslagenFromSource = $this->syncAanslagenService->fetchAndSyncAanslagen($filter['bsn'], $filter['belastingjaar-vanaf']);

        // $filter = [];
        if (isset($vraagBericht['ns2:body']['ns2:OPO']['ns2:aanslagBiljetNummer']) === true || isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer']) === true) {
            $filter['aanslagbiljetnummer'] = ($vraagBericht['ns2:body']['ns2:OPO']['ns2:aanslagBiljetNummer'] ?? $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer']);
        }

        if (isset($vraagBericht['ns2:body']['ns2:OPO']['ns2:aanslagBiljetVolgNummer']) === true || isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetVolgNummer']) === true) {
            $filter['aanslagbiljetvolgnummer'] = ($vraagBericht['ns2:body']['ns2:OPO']['ns2:aanslagBiljetVolgNummer'] ?? $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetVolgNummer']);
        }

        // Old way of finding a single Aanslag object in the gateway
        // $aanslagen = $this->cacheService->searchObjects(null, $filter, [$this::SCHEMA_REFS['Aanslagbiljet']]);
        // (new) way of finding a single Aanslag object with aanslagbiljetnummer & aanslagbiljetvolgnummer filters
        $aanslagen['count'] = 0;
        foreach ($aanslagenFromSource as $aanslag) {
            if (isset($filter['aanslagbiljetnummer']) === true
                && $filter['aanslagbiljetnummer'] === $aanslag['aanslagbiljetnummer']
                && isset($filter['aanslagbiljetvolgnummer']) === true
                && $filter['aanslagbiljetvolgnummer'] === $aanslag['aanslagbiljetvolgnummer']
            ) {
                $aanslagen['results'][] = $aanslag;
                $aanslagen['count']     = ($aanslagen['count'] + 1);
            }
        }

        if ($aanslagen['count'] > 1) {
            $this->logger->warning('Found more than 1 aanslag with these filters: ', $filter);
            return $this->createResponse(['Error' => 'Found more than 1 aanslag with these filters', 'Filters' => $filter], 500);
        } else if ($aanslagen['count'] === 1) {
            $aanslagen['result'] = ($aanslagen['results'][0] ?? $aanslagen['results']);
        }

        $aanslagen['vraagbericht'] = $vraagBericht;

        $responseContext = $this->mappingService->mapping($mapping, $aanslagen);

        return $this->createResponse($responseContext, 200);

    }//end getAanslag()


    /**
     * Create a bezwaar object based on the input.
     *
     * @param array $kennisgevingsBericht The kennisgevingsBericht content from the body of the current request.
     *
     * @return Response
     */
    public function createBezwaar(array $kennisgevingsBericht): Response
    {
        $bezwaarSchema = $this->resourceService->getSchema($this::SCHEMA_REFS['BezwaarAanvraag'], $this::PLUGIN_NAME);
        if ($bezwaarSchema === null) {
            return $this->createResponse(['Error' => "No schema found for {$this::SCHEMA_REFS['BezwaarAanvraag']}."], 501);
        }

        $responseMapping = $this->resourceService->getMapping($this::MAPPING_REFS['PostBezwaarResponse'], $this::PLUGIN_NAME);
        if ($responseMapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['PostBezwaarResponse']}."], 501);
        }

        $bezwaarArray = $this->mapXMLToBezwaar($kennisgevingsBericht);

        if ($bezwaarArray instanceof Response === true) {
            return $bezwaarArray;
        }

        $bezwaarObject = new ObjectEntity($bezwaarSchema);
        // $bezwaarArray  = $this->mappingService->mapping($mapping, $vraagBericht);
        $bezwaarObject->hydrate($bezwaarArray);

        $this->entityManager->persist($bezwaarObject);
        $this->entityManager->flush();

        $event = new ActionEvent('commongateway.object.create', ['response' => $bezwaarObject->toArray(), 'reference' => $bezwaarSchema->getReference()]);
        $this->eventDispatcher->dispatch($event, $event->getType());

        // In case of an error from Open Belastingen API
        if (isset($event->getData()['response']['Error'])) {
            return $this->createResponse($event->getData()['response'], 500);
        }

        $responseArray = $this->mappingService->mapping($responseMapping, $kennisgevingsBericht);

        return $this->createResponse($responseArray, 201);

    }//end createBezwaar()


    /**
     * Map a bezwaar array based on the input.
     *
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return Response|array
     */
    private function mapXMLToBezwaar(array $kennisgevingsBericht)
    {
        $errorResponse = $this->bezwaarRequiredFields($kennisgevingsBericht);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $bezwaarArray                   = [];
        $bezwaarArray['aanvraagnummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagnummer'] ?? null;

        $bezwaarArray['aanvraagdatum'] = null;
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']) === true) {
            $dateTime = DateTime::createFromFormat('YmdHisu', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            if ($dateTime === false) {
                $dateTime = DateTime::createFromFormat('Ymd', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            }

            if ($dateTime !== false) {
                $bezwaarArray['aanvraagdatum'] = $dateTime->format('Y-m-d');
            }
        }//end if

        $bezwaarArray['gehoordWorden'] = false;
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden']) === true
            && $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden'] === 'J'
        ) {
            $bezwaarArray['gehoordWorden'] = true;
        }

        $bezwaarArray = $this->mapExtraElementen($bezwaarArray, $kennisgevingsBericht);

        $bezwaarArray['belastingplichtige']['burgerservicenummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer'];

        // Bijlagen
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT']) === true) {
            $bijlagen = [];
            if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT']["ns2:ATT"]) === true) {
                $bijlagen[] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT'];
            } else {
                $bijlagen = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT'];
            }

            foreach ($bijlagen as $bijlage) {
                $bezwaarArray['bijlagen'][] = [
                    'naamBestand' => $bijlage['ns2:ATT']['ns2:naam'],
                    'typeBestand' => $bijlage['ns2:ATT']['ns2:type'],
                    'bestand'     => $bijlage['ns2:ATT']['ns2:bestand'],
                ];
            }
        }//end if

        // As long as we always map with a default value = null for each key, we should catch any missing properties (extraElementen for example)
        foreach ($bezwaarArray as $key => $property) {
            if ($property === null) {
                return $this->createResponse(['Error' => "No $key given."], 400);
            }
        }//end foreach

        return $bezwaarArray;

    }//end mapXMLToBezwaar()


    /**
     * Checks if the given $kennisgevingsBericht array has the minimal (some of the) required fields for creating a bezwaar.
     * Most other fields we get from extraElementen we check later on and not through this function.
     *
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return Response|null Null if everything is in order, an error Response if any required fields are missing.
     */
    private function bezwaarRequiredFields(array $kennisgevingsBericht): ?Response
    {
        // We do not send this to Pink Api, we only need this to return a correct xml response.
        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:referentienummer']) === false) {
            return $this->createResponse(['Error' => "No referentienummer given."], 400);
        }

        // We do not send this to Pink Api, we only need this to return a correct xml response.
        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:tijdstipBericht']) === false) {
            return $this->createResponse(['Error' => "No tijdstipBericht given."], 400);
        }

        // We check this here, because this is a way to return a more specific error message.
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer']) === false) {
            return $this->createResponse(['Error' => "No bsn given."], 400);
        }

        // We check this here, because this is a way to return a more specific error message.
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement']) === false) {
            return $this->createResponse(['Error' => "No 'ns2:extraElementen' given."], 400);
        }

        return null;

    }//end bezwaarRequiredFields()


    /**
     * Map the extraElementen for a bezwaar creation request. Using the $kennisgevingsBericht as input.
     *
     * @param array $bezwaarArray         The array we saved the mapped data in. This should contain the mapping done so far.
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return array The updated $bezwaarArray with applied mapping for extraElementen.
     */
    private function mapExtraElementen(array $bezwaarArray, array $kennisgevingsBericht): array
    {
        // Keep track of groups of 'codeGriefSoort', 'toelichtingGrief' & 'keuzeOmschrijvingGrief' from the 'ns2:extraElementen' in this $regelsData['regels'] array
        // We need 'regels' => [0 => []] for the first isset($regelsData['regels'][count($regelsData['regels']) - 1]['...']) check to work.
        // Also keep track of all 'belastingplichtnummers' & 'beschikkingSleutels'
        $regelsData = [
            'regels'                 => [0 => []],
            'belastingplichtnummers' => [],
            'beschikkingSleutels'    => [],
        ];

        // Make sure to always add these, so we return an error response if they are still null after handling all extraElementen
        $bezwaarArray['aanslagbiljetnummer']     = null;
        $bezwaarArray['aanslagbiljetvolgnummer'] = null;

        // Get all data from the extraElementen and add it to $bezwaarArray or $regelsData.
        foreach ($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement'] as $element) {
            if (!is_array($element)) {
                $element['#'] = $element;
            }

            $this->getExtraElementData($bezwaarArray, $regelsData, $element);
        }

        return $this->mapRegelsData($bezwaarArray, $regelsData);

    }//end mapExtraElementen()


    /**
     * A function used to map a single extra element and add it to $bezwaarArray or add it to $regelsData to be handled later.
     *
     * @param array $bezwaarArray The array we save the mapped data in.
     * @param array $regelsData   An array used to correctly map all aanslagRegels & beschikkingsregels later on.
     * @param array $element      The data of a single element from the extraElementen array.
     *
     * @return void
     */
    private function getExtraElementData(array &$bezwaarArray, array &$regelsData, array $element)
    {
        switch ($element['@naam']) {
        case 'kenmerkNummerBesluit':
            isset($bezwaarArray['aanslagbiljetnummer']) === false && $bezwaarArray['aanslagbiljetnummer'] = $element['#'];
            break;
        case 'kenmerkVolgNummerBesluit':
            isset($bezwaarArray['aanslagbiljetvolgnummer']) === false && $bezwaarArray['aanslagbiljetvolgnummer'] = $element['#'];
            break;
        case 'codeRedenBezwaar':
            // todo codeRedenBezwaar ?
            break;
        case 'keuzeOmschrijvingRedenBezwaar':
            // todo keuzeOmschrijvingRedenBezwaar ?
            break;
        case 'belastingplichtnummer':
            $regelsData['belastingplichtnummers'][] = $element['#'];
            break;
        case 'codeGriefSoort':
            if (isset($regelsData['regels'][(count($regelsData['regels']) - 1)]['codeGriefSoort']) === true) {
                $regelsData['regels'][] = ['codeGriefSoort' => $element['#']];
                break;
            }

            $regelsData['regels'][(count($regelsData['regels']) - 1)]['codeGriefSoort'] = $element['#'];
            break;
        case 'toelichtingGrief':
            if (isset($regelsData['regels'][(count($regelsData['regels']) - 1)]['toelichtingGrief']) === true) {
                $regelsData['regels'][] = ['toelichtingGrief' => $element['#']];
                break;
            }

            $regelsData['regels'][(count($regelsData['regels']) - 1)]['toelichtingGrief'] = $element['#'];
            break;
        case 'keuzeOmschrijvingGrief':
            if (isset($regelsData['regels'][(count($regelsData['regels']) - 1)]['keuzeOmschrijvingGrief']) === true) {
                $regelsData['regels'][] = ['keuzeOmschrijvingGrief' => $element['#']];
                break;
            }

            $regelsData['regels'][(count($regelsData['regels']) - 1)]['keuzeOmschrijvingGrief'] = $element['#'];
            break;
        case 'beschikkingSleutel':
            $regelsData['beschikkingSleutels'][] = $element['#'];
            break;
        default:
            break;
        }//end switch

    }//end getExtraElementData()


    /**
     * Map the data for aanslagRegels & beschikkingsregels with the information we got fromt he extraElementen, stored in the $regelsData array.
     *
     * @param array $bezwaarArray The array we saved the mapped data in. This should contain the mapping done so far.
     * @param array $regelsData   An array used to correctly map all aanslagRegels & beschikkingsregels.
     *
     * @return array The updated $bezwaarArray with applied mapping for aanslagRegels & beschikkingsregels.
     */
    private function mapRegelsData(array $bezwaarArray, array $regelsData): array
    {
        // Loop through all $regelsData['regels'] groups and add them to $bezwaarArray 'aanslagregels' or 'beschikkingsregels'
        foreach ($regelsData['regels'] as $key => $regel) {
            if (isset($regel['codeGriefSoort']) === false) {
                // If we ever get here the structure of the XML request body extraElementen is most likely incorrect.
                // (or what we were told, how to map this, was incorrect)
                $this->logger->error("Something went wrong while creating a 'bezwaar', found a 'regel' without a 'codeGriefSoort'.");
                continue;
            }

            // 'aanslagregels' & 'beschikkingsregels' both use the same data structure for 'grieven'
            $grief = [
                'soortGrief'       => $regel['codeGriefSoort'],
                'toelichtingGrief' => ($regel['keuzeOmschrijvingGrief'] ?? '').(isset($regel['keuzeOmschrijvingGrief']) && isset($regel['toelichtingGrief']) ? ' - ' : '').($regel['toelichtingGrief'] ?? ''),
            ];

            // The first items in $regelsData['regels'] array are always 'aanslagregels', equal to the amount of 'belastingplichtnummers' are present.
            if ($key < count($regelsData['belastingplichtnummers'])) {
                $bezwaarArray = $this->mapAanslagRegel(
                    $bezwaarArray,
                    $regelsData['belastingplichtnummers'][$key],
                    $grief
                );

                continue;
            }//end if

            // The last items in $regelsData['regels'] array are always 'beschikkingsregels', equal to the amount of 'sleutelBeschikkingsregel' are present.
            if (($key - count($regelsData['belastingplichtnummers'])) < count($regelsData['beschikkingSleutels'])) {
                $bezwaarArray = $this->mapBeschikkingsRegel(
                    $bezwaarArray,
                    $regelsData['beschikkingSleutels'][($key - count($regelsData['belastingplichtnummers']))],
                    $grief
                );
            }//end if
        }//end foreach

        return $bezwaarArray;

    }//end mapRegelsData()


    /**
     * Maps the data of a single $grief to the aanslagRegel with $belastingplichtnummer.
     *
     * @param array  $bezwaarArray          The array we saved the mapped data in. This should contain the mapping done so far.
     * @param string $belastingplichtnummer The belastingplichtnummer for this aanslagRegel.
     * @param array  $grief                 The data of the grief to add to the aanslagRegel with $belastingplichtnummer.
     *
     * @return array The updated $bezwaarArray with applied mapping for a single aanslagRegel.
     */
    private function mapAanslagRegel(array $bezwaarArray, string $belastingplichtnummer, array $grief): array
    {
        // Check if we already have an aanslagregel with this $belastingplichtnummer, if so add this $grief to that aanslagregel.
        if (isset($bezwaarArray['aanslagregels'])) {
            $aanslagregels = array_filter(
                $bezwaarArray['aanslagregels'],
                function (array $aanslagregel) use ($belastingplichtnummer) {
                    return $aanslagregel['belastingplichtnummer'] === $belastingplichtnummer;
                }
            );
            if (count($aanslagregels) > 0) {
                $bezwaarArray['aanslagregels'][array_key_first($aanslagregels)]['grieven'][] = $grief;

                return $bezwaarArray;
            }
        }

        // If there does not exist an 'aanslagregel' with $belastingplichtnummer yet add it.
        $bezwaarArray['aanslagregels'][] = [
            // Make sure belastingplichtnummer has leading zero's when the integer has less than 13 digits.
            'belastingplichtnummer' => sprintf("%013d", $belastingplichtnummer),
            'grieven'               => [0 => $grief],
        ];

        return $bezwaarArray;

    }//end mapAanslagRegel()


    /**
     * Maps the data of a single $grief to the beschikkingsRegel with $beschikkingSleutel.
     *
     * @param array  $bezwaarArray       The array we saved the mapped data in. This should contain the mapping done so far.
     * @param string $beschikkingSleutel The beschikkingSleutel for this beschikkingsRegel.
     * @param array  $grief              The data of the grief to add to the beschikkingsRegel with $beschikkingSleutel.
     *
     * @return array The updated $bezwaarArray with applied mapping for a single beschikkingsRegel.
     */
    private function mapBeschikkingsRegel(array $bezwaarArray, string $beschikkingSleutel, array $grief): array
    {
        // Check if we already have a beschikkingsregel with this $beschikkingSleutel, if so add this $grief to that beschikkingsregel.
        if (isset($bezwaarArray['beschikkingsregels'])) {
            $beschikkingsregels = array_filter(
                $bezwaarArray['beschikkingsregels'],
                function (array $beschikkingsregel) use ($beschikkingSleutel) {
                    return $beschikkingsregel['sleutelBeschikkingsregel'] === $beschikkingSleutel;
                }
            );
            if (count($beschikkingsregels) > 0) {
                $bezwaarArray['beschikkingsregels'][array_key_first($beschikkingsregels)]['grieven'][] = $grief;

                return $bezwaarArray;
            }
        }

        // If there does not exist a 'beschikkingsregel' with $beschikkingSleutel yet add it.
        $bezwaarArray['beschikkingsregels'][] = [
            'sleutelBeschikkingsregel' => $beschikkingSleutel,
            'grieven'                  => [0 => $grief],
        ];

        return $bezwaarArray;

    }//end mapBeschikkingsRegel()


    /**
     * Creates a response based on content.
     *
     * @param array $content The content to incorporate in the response
     * @param int   $status  The status code of the response
     *
     * @return Response
     */
    public function createResponse(array $content, int $status): Response
    {
        $this->logger->debug('Creating XML response');
        $xmlEncoder                = new XmlEncoder(['xml_root_node_name' => 'soapenv:Envelope']);
        $content['@xmlns:soapenv'] = 'http://schemas.xmlsoap.org/soap/envelope/';
        $contentString             = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);
        $contentString             = $this->replaceCdata($contentString);

        return new Response($contentString, $status, ['Content-Type' => 'application/soap+xml']);

    }//end createResponse()


    /**
     * Removes CDATA from xml array content
     *
     * @param string $contentString The content to incorporate in the response
     *
     * @return string The updated array.
     */
    private function replaceCdata(string $contentString): string
    {
        $contentString = str_replace(["<![CDATA[", "]]>"], "", $contentString);

        $contentString = preg_replace_callback(
            '/&amp;amp;amp;#([0-9]{3});/',
            function ($matches) {
                return chr((int) $matches[1]);
            },
            $contentString
        );

        return $contentString;

    }//end replaceCdata()


}//end class
