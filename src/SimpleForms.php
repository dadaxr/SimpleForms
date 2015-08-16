<?php

namespace Bolt\Extension\Bolt\SimpleForms;

use Bolt\Application;

/**
 * SimpleForms functionality class
 */
class SimpleForms
{
    /** @var Application */
    private $app;
    /** @var array */
    private $config;
    /** @var array */
    private $boltFormsExt;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->config = $this->app[Extension::CONTAINER]->config;

        $bfContainer = \Bolt\Extension\Bolt\BoltForms\Extension::CONTAINER;
        $this->boltFormsExt = $this->app[$bfContainer];
    }

    /**
     * Create a simple Form.
     *
     * @param string $formname
     * @param array  $with
     *
     * @return \Twig_Markup
     */
    public function simpleForm($formname = '', $with = array())
    {
        if (!isset($this->config[$formname])) {
            return new \Twig_Markup("<p><strong>SimpleForms is missing the configuration for the form named '$formname'!</strong></p>", 'UTF-8');
        }

        // Set our own reCaptcha key if provided
        $this->setupRecaptcha();
        $this->setupEmailDebug();

        $data = array();
        $options = array();
        $sent = false;
        $message = '';
        $error = '';
        $recaptchaResponse = array(
            'success'    => true,
            'errorCodes' => null
        );

        $this->app['boltforms']->makeForm($formname, 'form', $data, $options);

        $fields = $this->convertFormConfig($this->config[$formname]['fields']);

        // Add our fields all at once
        $this->app['boltforms']->addFieldArray($formname, $fields);

        // Handle the POST
        if ($this->app['request']->isMethod('POST')) {
            // Check reCaptcha, if enabled.
            $recaptchaResponse = $this->app['boltforms.processor']->reCaptchaResponse($this->app['request']);

            try {
                $sent = $this->app['boltforms.processor']->process($formname, $recaptchaResponse);
                $message = isset($this->config[$formname]['feedback']['success']) ? $this->config[$formname]['feedback']['success'] : 'Form submitted sucessfully';
            } catch (FileUploadException $e) {
                $error = $e->getMessage();
                $this->app['logger.system']->debug('[SimpleForms] File upload exception: ' . $error, array('event' => 'extensions'));
            } catch (FormValidationException $e) {
                $error = $e->getMessage();
                $this->app['logger.system']->debug('[SimpleForms] Form validation exception: ' . $error, array('event' => 'extensions'));
            }
        }

        // Get our values to be passed to Twig
        $use_ssl = $this->app['request']->isSecure();
        $fields = $this->app['boltforms']->getForm($formname)->all();
        $twigvalues = array(
            'submit'          => 'Send',
            'form'            => $this->app['boltforms']->getForm($formname)->createView(),
            'message'         => $message,
            'error'           => $error,
            'sent'            => $sent,
            'formname'        => $formname,
            'recaptcha_html'  => ($this->config['recaptcha_enabled'] ? recaptcha_get_html($this->config['recaptcha_public_key'], null, $use_ssl) : ''),
            'recaptcha_theme' => ($this->config['recaptcha_enabled'] ? $this->config['recaptcha_theme'] : ''),
            'button_text'     => $this->config['button_text']
        );

        // Render the Twig_Markup
        return $this->app['boltforms']->renderForm($formname, $this->config['template'], $twigvalues);
    }

    /**
     * Override the private key in BoltForms for reCaptcha.
     */
    protected function setupRecaptcha()
    {
        if (!empty($this->config['recaptcha_private_key'])) {
            $this->boltFormsExt->config['recaptcha']['private_key'] = $this->config['recaptcha_private_key'];
        }
    }

    /**
     * Override email debug settings in BoltForms.
     */
    protected function setupEmailDebug()
    {
        $this->boltFormsExt->config['debug']['enabled'] = $this->config['testmode'];
        $this->boltFormsExt->config['debug']['address'] = $this->config['testmode_recipient'];
    }

    /**
     * Convert SimpleForms field configuration to Symfony/BoltForms style.
     *
     * @param array $fields
     *
     * @return array
     */
    protected function convertFormConfig(array $fields)
    {
        $newFields = array();
        foreach ($fields as $field => $values) {
            $newFields[$field]['type'] = isset($values['type']) ? $values['type'] : 'submit';

            $newFields[$field]['options'] = array(
                'required' => isset($values['required']) ? $values['required'] : false,
                'label' => isset($values['label']) ? $values['label'] : null,
                'attr' => array(
                    'placeholder' => isset($values['placeholder']) ? $values['placeholder'] : null,
                    'class' => isset($values['class']) ? $values['class'] : null,
                ),
                'constraints' => $this->getContraints($field),
            );

            if ($newFields[$field]['type'] == 'choice') {
                $newFields[$field]['options']['choices'] = $values['choices'];
                $newFields[$field]['options']['multiple'] = isset($values['multiple']) ? $values['multiple'] : false;
            }
        }

        return $newFields;
    }

    /**
     * Get a set of validation constraints.
     *
     * @param array|string $field
     *
     * @retur array|null
     */
    protected function getContraints($field)
    {
        if (!is_array($field)) {
            return;
        }

        $constraints = array();
        if (isset($field['required']) && $field['required']) {
            $constraints[] = 'NotBlank';
        }
        if (isset($field['minlength']) || isset($field['maxlength'])) {
            $constraints[] = array('Length' => array(
                'min' => isset($field['minlength']) ? $field['minlength'] : null,
                'max' => isset($field['maxlength']) ? $field['maxlength'] : null,
            ));
        }

        return $constraints;
    }
}
