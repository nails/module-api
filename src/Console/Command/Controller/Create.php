<?php

namespace Nails\Api\Console\Command\Controller;

use Nails\Api\Exception\Console\ControllerExistsException;
use Nails\Console\Command\BaseMaker;
use Nails\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Create extends BaseMaker
{
    const RESOURCE_PATH   = NAILS_PATH . 'module-api/resources/console/';
    const CONTROLLER_PATH = FCPATH . 'application/modules/api/controllers/';

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this->setName('make:controller:api');
        $this->setDescription('Creates a new Api controller');
        $this->addArgument(
            'modelName',
            InputArgument::OPTIONAL,
            'Define the name of the model on which to base the controller'
        );
        $this->addArgument(
            'modelProvider',
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

            //  Validate model exists by attempting to load it
            Factory::model($aFields['MODEL_NAME'], $aFields['MODEL_PROVIDER']);

            //  Check for existing controller
            $sPath  = static::CONTROLLER_PATH . $aFields['MODEL_NAME'] . '.php';
            if (file_exists($sPath)) {
                throw new ControllerExistsException(
                    'Controller "' . $aFields['MODEL_NAME'] . '" exists already at path "' . $sPath . '"'
                );
            }

            $this->createFile($sPath, $this->getResource('template/controller.php', $aFields));

        } catch (ControllerExistsException $e) {
            //  Do not clean up (delete existing controller)!
            throw new \Exception($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            //  Clean up
            if (!empty($sPath)) {
                @unlink($sPath);
            }
            throw new \Exception($e->getMessage());
        }
    }
}
