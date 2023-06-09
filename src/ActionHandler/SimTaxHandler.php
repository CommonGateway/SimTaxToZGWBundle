<?php
/**
 * The handler that deals with incomming simTax files.
 *
 * @author  Wilco Louwerse <wilco@conduction.nl>, Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\SimTaxToZGWBundle\Service\SimTaxService;


class SimTaxHandler implements ActionHandlerInterface
{

    /**
     * The sim tax service used by the handler
     *
     * @var SimTaxService
     */
    private SimTaxService $simTaxService;


    /**
     * The constructor
     *
     * @param SimTaxService $simTaxService The sim tax service
     */
    public function __construct(SimTaxService $simTaxService)
    {
        $this->simTaxService = $simTaxService;

    }//end __construct()


    /**
     * Returns the required configuration as a https://json-schema.org array.
     *
     * @return array The configuration that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://dowr.simxml.nl/ActionHandler/SimTaxToZGWHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SimTaxToZGW ActionHandler',
            'description' => 'This handler returns a welcoming string',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the service.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     *
     * @SuppressWarnings("unused") Handlers ara strict implementations
     */
    public function run(array $data, array $configuration): array
    {
        return $this->simTaxService->simTaxHandler($data, $configuration);

    }//end run()


}//end class
