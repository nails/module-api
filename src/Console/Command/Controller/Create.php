<?php

namespace Nails\Api\Console\Command\Controller;

use Nails\Console\Command\BaseMaker;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Create extends BaseMaker
{
    const RESOURCE_PATH   = NAILS_PATH . 'nailsapp/module-api/resources/console/';
    const CONTROLLER_PATH = FCPATH . 'application/modules/api/';

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this->setName('make:controller:api');
        $this->setDescription('[WIP] Creates a new Api controller');
        $this->addArgument(
            'modelName',
            InputArgument::OPTIONAL,
            'Define the name of the model on which to base the controller'
        );
        $this->addArgument(
            'providerName',
            InputArgument::OPTIONAL,
            'Define the provider of the model',
            'app'
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Executes the app
     *
     * @param  InputInterface $oInput The Input Interface provided by Symfony
     * @param  OutputInterface $oOutput The Output Interface provided by Symfony
     * @return int
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);

        // --------------------------------------------------------------------------

        try {
            //  Ensure the paths exist
            $this->createPath(self::CONTROLLER_PATH);
            //  Create the controller
            $this->createController();
        } catch (\Exception $e) {
            return $this->abort(
                self::EXIT_CODE_FAILURE,
                $e->getMessage()
            );
        }

        // --------------------------------------------------------------------------

        //  Cleaning up
        $oOutput->writeln('');
        $oOutput->writeln('<comment>Cleaning up...</comment>');

        // --------------------------------------------------------------------------

        //  And we're done
        $oOutput->writeln('');
        $oOutput->writeln('Complete!');

        return self::EXIT_CODE_SUCCESS;
    }

    // --------------------------------------------------------------------------

    /**
     * Create the Model
     *
     * @throws \Exception
     * @return void
     */
    private function createController()
    {
        $aFields = $this->getArguments();

        try {

            dumpanddie($aFields);

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
