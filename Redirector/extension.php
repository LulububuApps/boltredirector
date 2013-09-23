<?php
// Redirector Extension 0.9 for Bolt
// Minimum Bolt version: 1.2
// http://code.foundrybusiness.co.za/bolt-redirector

namespace Redirector;

use Bolt\BaseExtension as BoltExtension;
use Silex\Application as Silex;
use Symfony\Component\HttpFoundation\Request;

class Extension extends BoltExtension
{
    public $wildcards = array(
        'all' => '.*',
        'alpha' => '[a-z]+',
        'num' => '[0-9]+',
        'alphanum' => '[a-z0-9]+',
        'any' => '[a-z0-9\.\-_%\=\s]+',
        'segment' => '[a-z0-9\-_]+',
        'segments' => '[a-z0-9\-_/]+',
        'ext' => 'aspx?|f?cgi|s?html?|jhtml|rbml|jsp|phps?',
    );

    public $smartWildcards = array(
        'path' => 'segments',
        'name|title|page|post|user|model' => 'segment',
        'year|month|day|id' => 'num',
        'ext' => 'ext',
    );

    public $errors = array (
        'assertion_invalid' => 'Invalid assertion condition supplied. Please provide a boolean condition.',
        'inavlid_variable_declaration' => 'Invalid variable declaration: A valid variable declaration starts with a letter or underscore, followed by any number of letters, numbers, or underscores.',
        'string_lh_destination' => 'Redirect destination must be a string.',
        'string_lh_jitsource' => 'JIT source must be a string.',
        'string_lh_source' => 'Redirect source must be a string.',
        'string_sh_jitsource' => 'Short-hand JIT source must be a string.',
        'string_sh_source' => 'Short-hand redirect source must be a string.',
        'missing_variables' => 'Un-processed variable in redirect destination. Please add the `%s` variable to the `variables` group in Redirector/config.yml. (Destination: %s)',
    );

    /**
     * Basic information about the extension. Shown in the Bolt Admin Environment.
     *
     * @return array
     */

    public function info()
    {
        $data = array(
            'name' => 'Redirector',
            'version' => '0.9',
            'author' => 'Mike Anthony / Foundry Code',
            'description' => 'A wicked little extension that allows you to perform any pre-app <code>301 Moved Permanently</code> redirects',
            'type' => 'SEO Enhancement',
            'link' => 'http://code.foundrybusiness.co.za/bolt-redirector',
            'support_email' => 'code@foundrybusiness.co.za',
            'first_releasedate' => '2013-09-10',
            'latest_releasedate' => '2013-09-18',
            'required_bolt_version' => '1.2',
            'highest_bolt_version' => '1.2.1'
        );

        return $data;
    }

    /**
     * Initialise the extension's functions
     *
     * @return void
     */
    public function initialize()
    {
        // Get the routing.yml configuration
        $this->routes = $this->app->config->get('routing');

        // Get the extension's configuration
        $this->initializeConfiguration();

        // Go!
        $this->handleRedirects();
    }

    /**
     * Initialise the extension's configuration
     *
     * @return void
     */
    public function initializeConfiguration()
    {
        // Set the configuration defaults
        $config = array(
            'options' => array(
                'autoslug' => true,
                'append_query_string' => false,
                'ignore_errors' => false,
            ),
            'redirects' => array(),
            'jits' => array(),
            'variables' => array(),
        );
        // Merge these with the actual configuration definitions
        // in config.yml, which take precedence over the defaults
        $this->config = array_merge($config, $this->config);

        // Assign configuration groups to arrays in object
        $configGroups = array('options', 'redirects', 'jits', 'variables');
        foreach($configGroups as $group) {
            if (!empty($this->config[$group])) {
                $this->$group = $this->config[$group];
            } else {
                // Take 'empty groups' from the .yml file into account.
                $this->$group = array();
            }
        }
    }

    /**
     * Throw an exception
     *
     * @return void
     * @throw \Exception
     */
    public function except($message = null, $code = null)
    {
        if (isset($this->errors[$message])) {
            $message = $this->errors[$message];
        }
        throw new \Exception($message, $code);
    }

    /**
     * Perform a value-assertion and throw an exception upon failure
     *
     * @return void
     */
    public function assert($condition = false, $error = 'assertion_invalid')
    {
        ($condition) or $this->except($error);
    }

    /**
     * Make input slugs more friendly. Like cats.
     *
     * Last Fix: 13 Sept 2013 - fixed problem with spaces
     *
     * @param $input
     * @return string
     */
    public function slugify($input)
    {
        $input = preg_replace("~%u([0-9a-f]{3,4})~i", "&#x\\1;", urldecode($input));
        $input = preg_replace('~[^\\pL\d\/]+~u', '-', $input);
        $input = trim($input, '-');
        $input = iconv('utf-8', 'us-ascii//TRANSLIT', $input);
        $input = strtolower($input);
        $input = preg_replace('~[^-\w\/]+~', '', $input);

        return (empty($input))? '' : $input;
    }

    /**
     * Check for a redirect. If it exists, then redirect to it's
     * converted replacement.
     *
     * @return void|RedirectResponse
     */
    public function handleRedirects()
    {
        $self = $this;
        $app = $this->app;

        // Register this extension's actions as an early event
        $app->before(function (Request $request) use ($self, $app) {
            if (empty($self->redirects)) {
                return;
            }
            $requestedPath = trim($request->getPathInfo(), '/');

            // Get the available wildcards, prepare for pattern match
            $availableWildcards = '';
            foreach ($self->wildcards as $wildcard => $expression) {
                $availableWildcards .= "$wildcard|";
            }
            $availableWildcards = rtrim($availableWildcards, '|');

            // Assign the wildcard pattern check
            $pattern = '~\{([a-z]+):(' . $availableWildcards . ')\}~';

            // Loop through defined redirects
            foreach ($self->redirects as $redirectName => $redirectData) {
                $self->computedReplacements = array();

                // Check for short-hand notation
                if (!is_array($redirectData)) {
                    $self->assert(is_string($redirectName), 'string_sh_source');
                    $self->source = trim($redirectName, '/');
                    $self->destination = trim($redirectData, '/');
                } else {
                    $self->assert(is_string($redirectData["from"]), 'string_lh_source');
                    $self->assert(is_string($redirectData["to"]), 'string_lh_destination');
                    $self->source = trim($redirectData['from'], '/');
                    $self->destination = trim($redirectData['to'], '/');
                }

                // Check for a query string
                $self->sourceQueryString = '';
                if ($self->options['append_query_string']) {
                    $queryString = $request->getQueryString();
                    if (!is_null($queryString)) {
                        $self->sourceQueryString = "?$queryString";
                    }
                }

                // Check for a non-capture group in the source and convert to regex equivalent
                $nonCaptureMatcher = "~<([a-z0-9\-_\|]+)>~";
                if (preg_match($nonCaptureMatcher, $self->source)) {
                    $self->source = preg_replace($nonCaptureMatcher, "(?:\\1)", $self->source);
                }


                // Check if we're redirecting to a route and apply the path if it exists
                $routeMatcher = "~^route\:\s+?([a-z\-_]+)$~";
                if (preg_match($routeMatcher, $self->destination, $matches)) {
                    if (isset($self->routes[$matches[1]])) {
                        $self->destination = trim($self->routes[$matches[1]]['path'], '/');
                    }
                }

                // Check for a protocol and apply a prefix based on the findings
                $self->prefix = '';
                if (!preg_match("~^(https?|ftps?)\://~", $self->destination)) {
                    $self->prefix = '/';
                }

                // Convert smart wildcards into normal ones
                foreach ($self->smartWildcards as $wildcard => $wildcardType) {
                    $smartWildcardMatcher = "~\{($wildcard)\}~i";
                    if (preg_match($smartWildcardMatcher, $self->source)) {
                        $self->source = preg_replace($smartWildcardMatcher, "{\\1:$wildcardType}", $self->source);
                    }
                }

                // Convert the wildcards into expressions for replacement
                $computedWildcards = preg_replace_callback($pattern, function ($captures) use ($self) {
                    $self->computedReplacements[] = $captures[1];

                    return '(' . $self->wildcards[$captures[2]] . ')';
                }, $self->source);

                // Check to see if we have these conversions in the requested path and replace where necessary
                if (preg_match("~^$computedWildcards$~i", $requestedPath)) {
                    $convertedWildcards = preg_replace_callback("~^$computedWildcards$~i", function ($captures) use ($self) {
                        $result = $self->destination;
                        for ($c = 1, $n = count($captures); $c < $n; ++$c) {
                            $value = array_shift($self->computedReplacements);
                            if ($self->options['autoslug']) {
                                $captures[$c] = $self->slugify($captures[$c]);
                            }
                            $result = str_replace('{' . $value . '}', $captures[$c], $result);
                        }

                        return $result;
                    }, $requestedPath);

                    // Merge global variables with those defined by the user
                    $self->variables = array_merge($self->variables, array(
                        'admin_path' => $app->config->get('general/branding/path'),
                    ));
                    // Replace variables with actual data
                    foreach ($self->variables as $variable => $data) {
                        $self->assert(preg_match('~^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$~', $variable), 'inavlid_variable_declaration');
                        $convertedWildcards = str_replace("{@$variable}", ltrim($data, '/'), $convertedWildcards);
                    }

                    // Check for Just In Time replacements and apply where necessary
                    foreach ($self->jits as $jitName => $jitData) {
                        // Check for short-hand notation
                        if (is_string($jitData)) {
                            $self->assert(is_string($jitName), 'string_sh_jitsource');
                            $jitReplace = $jitName;
                            $jitWith = $jitData;
                        } else {
                            $self->assert(is_string($jitData['replace']), 'string_lh_jitsource');
                            $jitReplace = $jitData['replace'];
                            $jitWith = $jitData['with'];
                        }
                        // Match and replace
                        $jitMatcher = "~$jitReplace~i";
                        if (preg_match($jitMatcher, $convertedWildcards)) {
                            $convertedWildcards = preg_replace($jitMatcher, trim($jitWith, '/'), $convertedWildcards);
                        }
                    }

                    // Check for un-processed variables and throw an exception if there are any
                    if (preg_match('~\{@(.*)\}~', $convertedWildcards, $variableMatches)) {
                        $self->except(sprintf($self->errors['missing_variables'], $variableMatches[1], $convertedWildcards));
                    }

                    // Redirect the user to the final, processed path
                    return $app->redirect(strtolower("{$self->prefix}{$convertedWildcards}{$self->sourceQueryString}"), 301);
                }
            }
        }, Silex::EARLY_EVENT);
    }
}
