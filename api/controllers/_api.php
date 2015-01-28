<?php

/**
 * This class provides some common API controller functionality
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class NAILS_API_Controller extends NAILS_Controller
{
    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Load language file
        $this->lang->load('api');
    }

    // --------------------------------------------------------------------------

    /**
     * Take $out and send t to the browser in the desired format
     * @param  array   $out        The data to output to the browser
     * @param  string  $format     The format the data should be sent as
     * @param  boolean $sendHeader Whether or not to send the status header
     * @return void
     */
    protected function _out($out = array(), $format = 'JSON', $sendHeader = true)
    {
        //  Set cache headers
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $this->output->set_header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        $this->output->set_header('Pragma: no-cache');

        $serverProtocol = $this->input->server('SERVER_PROTOCOL');

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        if (isset($out['status'])) {

            $out['status'] = (int) $out['status'];

            switch ($out['status']) {

                case 400:

                    $headerString = '400 Bad Request';
                    break;

                case 401:

                    $headerString = '401 Unauthorized';
                    break;

                case 404:

                    $headerString = '404 Not Found';
                    break;

                case 500:

                    $headerString = '500 Internal Server Error';
                    break;

                default:

                    $headerString = '200 OK';
                    break;

            }

        } elseif(is_array($out)) {

            $out['status'] = 200;
            $headerString  = '200 OK';

        } else {

            $headerString = '200 OK';
        }

        // --------------------------------------------------------------------------

        //  Send the header?
        if ($sendHeader) {

            $this->output->set_header($serverProtocol . ' ' . $headerString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        switch (strtoupper($format)) {

            case 'JSON':

                $this->output->set_content_type('application/json');
                $out = json_encode($out);
                break;

            case 'TXT':
            case 'TEXT':
            case 'HTML':

                $this->output->set_content_type('text/html');

                if (!is_string($out)) {

                    $out = serialize($out);
                }
                break;
        }

        $this->output->set_output($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Map requests to the appropriate method
     * @param  string $method the method to map to
     * @return void
     */
    public function _remap($method)
    {
        if (method_exists($this, $method)) {

            $this->{$method}();

        } else {

            $this->methodNotFound($method);

        }
    }

    // --------------------------------------------------------------------------

    /**
     * Outputs a JSON response if the method isn't found and halts execution
     * @param  string $method the method which was not found
     * @return void
     */
    protected function methodNotFound($method)
    {
         $this->_out(array(
            'status' => 400,
            'error'  => lang('not_valid_method', $method)
        ));

         // Careful now, this might break in future updates of CI
         echo $this->output->_display();
         exit(0);
    }
}
