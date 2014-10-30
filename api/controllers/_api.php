<?php

/**
 * Name:        NALS_API_Controller
 *
 * Description: This controller executes various bits of common admin API functionality
 *
 **/


class NAILS_API_Controller extends NAILS_Controller
{
    /**
     *  Execute common functionality
     *
     *  @access public
     *  @param  none
     *  @return void
     *
     **/
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Load language file
        $this->lang->load('api');
    }

    // --------------------------------------------------------------------------


    /**
     *  Take the input and spit it out as JSON
     *
     *  @access public
     *  @param  none
     *  @return void
     *
     **/
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
                    '500 Internal Server Error';
                    break;

                default:
                    $headerString = '200 OK';
                    break;

            }

        } elseif(is_array($out)) {

            $out['status']  = 200;
            $headerString   = '200 OK';

        } else {

            $headerString   = '200 OK';
        }

        // --------------------------------------------------------------------------

        //  Send the header?
        if ($sendHeader) {

            $this->output->set_header($serverProtocol . ' ' . $headerString);

        }

        // --------------------------------------------------------------------------

        //  Output content
        switch(strtoupper($format)) {

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
     *  Take the input and spit it out as JSON
     *
     *  @access public
     *  @param  none
     *  @return void
     *
     **/
    public function _remap($method)
    {
        if (method_exists($this, $method)) {

            $this->{$method}();

        } else {

            $this->_method_not_found($method);

        }
    }


    // --------------------------------------------------------------------------


    /**
     *  Output JSON for when a method is not found (or enabled)
     *
     *  @access public
     *  @param  none
     *  @return void
     *
     **/
    protected function _method_not_found($method)
    {
         $this->_out(array(
            'status' => 400,
            'error' => lang('not_valid_method', $method)
        ));

         // Careful now, this might break in future updates of CI
         echo $this->output->_display();
         exit(0);
    }
}

/* End of file _api.php */
/* Location: ./application/modules/api/controllers/_api.php */
