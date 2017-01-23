<?php

namespace Nails\Api\Console\Command\Controller;

use Nails\Console\Command\Base;
use Nails\Environment;
use Nails\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Create extends Base
{
    const CONTROLLER_PATH            = FCPATH . APPPATH . 'modules/api/controllers/';
    const CONTROLLER_PATH_PERMISSION = 0755;

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this->setName('api:controller');
        $this->setDescription('[WIP] Creates a new API controller based on an existing model');
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
        $oOutput->writeln('');
        $oOutput->writeln('<info>-------------------------</info>');
        $oOutput->writeln('<info>Nails API Controller Tool</info>');
        $oOutput->writeln('<info>-------------------------</info>');

        // --------------------------------------------------------------------------

        //  Setup Factory - config files are required prior to set up
        Factory::setup();

        // --------------------------------------------------------------------------

        //  Check environment
        if (Environment::not('DEVELOPMENT')) {
            return $this->abort(
                $oOutput,
                self::EXIT_CODE_FAILURE,
                [
                    'This tool is only available on DEVELOPMENT environments',
                ]
            );
        }

        // --------------------------------------------------------------------------

        //  Check we can write where we need to write
        if (!is_dir(self::CONTROLLER_PATH)) {
            if (!mkdir(self::CONTROLLER_PATH, self::CONTROLLER_PATH_PERMISSION, true)) {
                return $this->abort(
                    $oOutput,
                    self::EXIT_CODE_FAILURE,
                    [
                        'Controller directory does not exist and could not be created',
                        self::CONTROLLER_PATH,
                    ]
                );
            }
        } elseif (!is_writable(self::CONTROLLER_PATH)) {
            return $this->abort(
                $oOutput,
                self::EXIT_CODE_FAILURE,
                [
                    'Controller directory exists but is not writeable',
                    self::CONTROLLER_PATH,
                ]
            );
        }

        // --------------------------------------------------------------------------

        //  @todo request model, verify valid then confirm with user before creating controller
        //  @todo support defining multiple models

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
}
