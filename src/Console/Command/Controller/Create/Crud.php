<?php

namespace Nails\Api\Console\Command\Controller\Create;

use Nails\Api\Exception\Console\ControllerExistsException;
use Nails\Console\Command\BaseMaker;
use Nails\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Crud extends BaseMaker
{
    const RESOURCE_PATH   = NAILS_PATH . 'module-api/resources/console/';
    const CONTROLLER_PATH = NAILS_APP_PATH . 'src/Api/Controller/';

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('make:controller:api:crud')
            ->setDescription('Creates a new CRUD API controller')
            ->addArgument(
                'modelName',
                InputArgument::OPTIONAL,
                'The name of the model to which to bind'
            )
            ->addArgument(
                'modelProvider',
                InputArgument::OPTIONAL,
                'The provider of the model to which to bind',
                'app'
            );
    }

    // --------------------------------------------------------------------------

    /**
     * Executes the app
     *
     * @param InputInterface  $oInput  The Input Interface provided by Symfony
     * @param OutputInterface $oOutput The Output Interface provided by Symfony
     *
     * @return int
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
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
                [$e->getMessage()]
            );
        }

        // --------------------------------------------------------------------------

        //  Cleaning up
        $oOutput->writeln('');
        $oOutput->writeln('<comment>Cleaning up</comment>...');

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
     * @return void
     * @throws \Exception
     */
    private function createController(): void
    {
        $aFields  = $this->getArguments();
        $aCreated = [];

        try {

            $aModels = array_filter(explode(',', $aFields['MODEL_NAME']));

            foreach ($aModels as $sModel) {

                $aFields['MODEL_NAME'] = $sModel;
                $this->oOutput->write('Creating controller <comment>' . $sModel . '</comment>... ');

                //  Validate model exists by attempting to load it
                Factory::model($sModel, $aFields['MODEL_PROVIDER']);

                //  Check for existing controller
                $sPath = static::CONTROLLER_PATH . $sModel . '.php';
                if (file_exists($sPath)) {
                    throw new ControllerExistsException(
                        'Controller "' . $sModel . '" exists already at path "' . $sPath . '"'
                    );
                }

                $this->createFile($sPath, $this->getResource('template/controller_crud.php', $aFields));
                $aCreated[] = $sPath;
                $this->oOutput->writeln('<info>done</info>');
            }

        } catch (\Exception $e) {
            $this->oOutput->writeln('<error>fail</error>');
            //  Clean up created models
            if (!empty($aCreated)) {
                $this->oOutput->writeln('<error>Cleaning up - removing newly created controllers</error>');
                foreach ($aCreated as $sPath) {
                    @unlink($sPath);
                }
            }
            throw $e;
        }
    }
}
