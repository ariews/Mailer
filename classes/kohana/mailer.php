<?php defined('SYSPATH') or die('No direct script access.');

abstract class Kohana_Mailer
{
    /**
     * Swift_Mailer
     * @var object
     */
    protected $_mailer          = null;

    /**
     * Mail Type
     * @var string
     */
    protected $type             = null;

    /**
     * Sender mail
     * @var string
     */
    protected $from             = null;

    /**
     * Receipents mail
     * @var string
     */
    protected $to               = null;

    /**
     * CC
     * @var string
     */
    protected $cc               = null;

    /**
     * BCC
     * @var string
     */
    protected $bcc              = null;

    /**
     * Mail Subject
     * @var string
     */
    protected $subject          = null;

    /**
     * Data binding
     * @var array
     */
    protected $data             = null;

    /**
     * Attachments
     * @var array
     */
    protected $attachments      = null;

    /**
     * Whether in batch send or no
     * @var boolean
     */
    protected $batch_send       = false;

    /**
     * Swift_Message Object
     * @var object
     */
    protected $message          = null;

    /**
     * Mailer Config
     * @var object
     */
    protected $config           = null;

    /**
     * Mail template
     * @var array
     */
    protected $view = array(
        'html'  => null,
        'text'  => null,
    );

    public function __construct($config = null)
    {
        // Load configuration
        ($config === null) AND $config = Kohana::config('mailer');
        
        $this->config = $config;

        $this->connect();
    }

    /**
     * factory
     *
     * @access public
     * @param  void
     * @return void
     * 
     **/
    public static function factory($mailer_name, $config = null)
    {
        $class = 'Mailer_'.ucfirst($mailer_name);
        return new $class($config);
    }

    /**
     * connect
     *
     * @access public
     * @param  void	
     * @return void
     * 
     **/
    public function connect()
    {
        if ( ! class_exists('Swift', false))
        {
            // Load SwiftMailer Autoloader
            require_once Kohana::find_file('vendor', 'swift/swift_required');
        }

        switch ($this->config->transport)
        {
            case 'smtp':
                $transport = Swift_SmtpTransport::newInstance()
                                ->setHost($this->config->options['hostname'])
                                ->setUsername($this->config->options['username'])
                                ->setPassword($this->config->options['password']);

                $port = empty($this->config->options['port']) ? null : (int) $this->config->options['port'];
                $transport->setPort($port);

                if (! empty($this->config->options['encryption']))
                {
                    $transport->setEncryption($this->config->options['encryption']);
                }
            break;

            case 'sendmail':
                $transport = Swift_SendmailTransport::newInstance();
            break;

            default:
                $transport = Swift_MailTransport::newInstance();
            break;
        }

        return $this->_mailer = Swift_Mailer::newInstance($transport);
    }

    public function __call($name, $args = array())
    {
        foreach ($args[0] as $key => $value)
        {
            if (preg_match('/^(type|from|to|cc|bcc|subject|data|attachments|batch_send)$/i', $key))
            {
                $this->$key = $value;
            }
        }

        if (preg_match('/^sen(d|t)_/i', $name))
        {
            $method = substr($name, 5, strlen($name));
            if (method_exists($this, $method))
            {
                $this->$method($args);
                $this->setup_message($method);
                $this->send();
            } else {
                throw new Exception('Method: '.$method.' does not exist.');
            }
        }
    }

    public function setup_message($method)
    {
        $this->message = Swift_Message::newInstance();
        $this->message->setSubject($this->subject);

        // View
        $template = strtolower(preg_replace('/_/', '/', get_class($this)) . "/{$method}");
        $text     = View::factory($template);

        $this->set_data($text);
        $this->view['text'] = $text->render();

        if ($this->type == 'html')
        {
            try {
                // first attempt to load template.html.php
                $template = View::factory("{$template}.html");
                $this->set_data($template);
                $this->view['html'] = $template->render();

            } catch (Kohana_View_Exception $e)
            {
                // make sure to have Markdown enabled
                if (! function_exists('Markdown'))
                {
                    require_once Kohana::find_file('vendor','markdown/markdown');
                }

                // use default
                $this->view['html'] = Markdown($this->view['text']);
            }

            $this->message->setBody($this->view['html'], 'text/html');
            $this->message->addPart($this->view['text'], 'text/plain');
        } else {
            $this->message->setBody($this->view['text'], 'text/plain');
        }

        if ($this->attachments !== null)
        {
            if (! is_array($this->attachments))
            {
                $this->attachments = array($this->attachments);
            }

            foreach ($this->attachments as $file)
            {
                $this->message->attach(Swift_Attachment::fromPath($file));
            }
        }

        // to
        if (! is_array($this->to))
        {
            $this->to = array($this->to);
        }
        $this->message->setTo($this->to);

        // cc
        if ($this->cc !== null)
        {
            if (! is_array($this->cc))
            {
                $this->cc = array($this->cc);
            }
            $this->message->setCc($this->cc);
        }

        // bcc
        if ($this->bcc !== null)
        {
            if (! is_array($this->bcc))
            {
                $this->bcc = array($this->bcc);
            }
            $this->message->setBcc($this->bcc);
        }

        // from
        $this->message->setFrom($this->from);

        return $this;
    }

    public function send()
    {
        if (! $this->batch_send)
        {
            $result = $this->_mailer->send($this->message);
        } else {
            $result = $this->_mailer->batchSend($this->message);
        }

        return $result;
    }

    public function set_data(& $view)
    {
        if ($this->data != null)
        {
            if (! is_array($this->data))
            {
                $this->data = array($this->data);
            }

            foreach ($this->data as $key => $value)
            {
                $view->bind($key, $this->data[$key]);
            }
        }
        return $view;
    }
}