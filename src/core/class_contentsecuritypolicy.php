<?php

namespace Doreen;

/**
 *  Implements the Doreen content security policy for the Content-Security-Policy
 *  field in the HTTP header. This is generated with \ref WholePage::BuildCSP()
 *  during WholePage::EmitHeader().
 *
 *  Please refer to https://developer.mozilla.org/docs/Web/HTTP/CSP for
 *  full explanations of CSP. The terminology should be close.
 */
class ContentSecurityPolicy
{
    /*
     * Sources are documents loaded via a network request. These constants
     * refer to special resources that either don't involve the network, or self
     * as a shorthand for the origin of the parent document.
     * Other values for sources must be an origin with protocol.
     */
    const SOURCE_NONE = "'none'";
    const SOURCE_SELF = "'self'";
    const SOURCE_UNSAFE_INLINE = "'unsafe-inline'";
    const SOURCE_UNSAFE_EVAL = "'unsafe-eval'";

    /*
     * Directives are the reasons a request is made that sources have to be
     * allowed for.
     */
    const DIRECTIVE_ANCESTORS = 'frame-ancestors';
    const DIRECTIVE_DEFAULT = 'default-src';
    const DIRECTIVE_FRAME = 'frame-src';
    const DIRECTIVE_IMG = 'img-src';
    const DIRECTIVE_OBJECT = 'object-src';
    const DIRECTIVE_SCRIPT = 'script-src';
    const DIRECTIVE_STYLE = 'style-src';
    const DIRECTIVE_WORKER = 'worker-src';
    const DIRECTIVE_MEDIA = 'media-src';

    const DIRECTIVE_DELIMITER = ';';
    const SOURCE_DELIMITER = ' ';

    const HASH_TYPE = 'sha512';

    /*
     * List of directives that default to the default-src directive when unset.
     *
     * @var array
     */
    const FALLBACK_TO_DEFAULT = [
        'connect-src',
        'font-src',
        self::DIRECTIVE_FRAME,
        self::DIRECTIVE_IMG,
        'manifest-src',
        self::DIRECTIVE_MEDIA,
        self::DIRECTIVE_OBJECT,
        self::DIRECTIVE_SCRIPT,
        self::DIRECTIVE_STYLE,
        self::DIRECTIVE_WORKER
    ];

    /*
     * Default CSP directives and sources. Sources are an associative array,
     * so every source is only set once per directive.
     *
     * @var array
     */
    const DEFAULT_CSP = [
        self::DIRECTIVE_DEFAULT => [
            self::SOURCE_SELF => 1
        ],
        self::DIRECTIVE_ANCESTORS => [
            self::SOURCE_SELF => 1
        ],
        'form-action' => [
            self::SOURCE_SELF =>  1
        ],
        self::DIRECTIVE_OBJECT => [
            self::SOURCE_NONE => 1
        ],
        self::DIRECTIVE_STYLE => [
            self::SOURCE_UNSAFE_INLINE => 1
        ],
        self::DIRECTIVE_FRAME => [
            'data:' => 1
        ]
    ];

    private $rules = self::DEFAULT_CSP;

    public function __construct()
    {

    }

    /**
     *  Allow an inline-source based on its contents that are hashed and added
     *  to the CSP in that form.
     */
    public function addSourceHash(string $directive, string $source)
    {
        $hash = base64_encode(hash(self::HASH_TYPE, $source, true));
        $source = "'".self::HASH_TYPE."-$hash'";
        $this->allow($directive, $source);
    }

    /**
     *  Allow a source for a specific directive. If the source is the 'none'
     *  special source, all other sources are removed.
     */
    public function allow(string $directive, string $source)
    {
        if ($source === self::SOURCE_NONE)
        {
            $this->rules[$directive] = [
                $source => 1
            ];
        }
        else
            $this->rules[$directive][$source] = 1;
    }

    /**
     *  Remove a source from the allowed sources for a directive.
     */
    public function remove(string $directive, string $source)
    {
        unset($this->rules[$directive][$source]);
    }

    /**
     *  Returns the definitive sources for a directive, merging in the
     *  default-src when appropriate.
     *
     *  @return array
     */
    private function getDirective(string $directive)
        : array
    {
        $items = $this->rules[$directive];
        if (in_array($directive, self::FALLBACK_TO_DEFAULT) && !array_key_exists(self::SOURCE_NONE, $items))
            $items = array_merge($this->rules[self::DIRECTIVE_DEFAULT], $items);

        return $items;
    }

    /**
     *  Format a directive and its sources for the header.
     *
     *  @return string
     */
    private function formatDirective(string $directive)
        : string
    {
        $items = $this->getDirective($directive);
        if (empty($items))
            return '';

        return $directive.self::SOURCE_DELIMITER.implode(self::SOURCE_DELIMITER, array_keys($items)).self::DIRECTIVE_DELIMITER;
    }

    /**
     *  Build the full CSP header value.
     *
     *  @return string
     */
    public function emit()
        : string
    {
        $csp = '';
        foreach($this->rules as $directive => $sources) {
            $csp .= $this->formatDirective($directive);
        }
        return $csp;
    }

    /**
     *  Build X-Frame-Options header value based on the CSP.
     *
     *  @return string
     */
    public function emitFrameOptions()
        : string
    {
        $option = 'SAMEORIGIN';

        $items = $this->getDirective(self::DIRECTIVE_ANCESTORS);

        if (!empty($items))
        {
            foreach (array_keys($items) as $source)
            {
                if ($source === self::SOURCE_NONE)
                {
                    $option = 'DENY';
                    break;
                }
                else if ($source === self::SOURCE_SELF)
                {
                    $option = 'SAMEORIGIN';
                    break;
                }
                else {
                    if ($option === 'SAMEORIGIN')
                        $option = '';
                    $option .= 'ALLOW-FROM '.$source.' ';
                }
            }
        }

        return $option;
    }

    /**
     *  Makes a valid source out of an arbitrary URL.
     *
     *  @return string
     */
    public static function MakeSourceFromURL(string $url)
        : string
    {
        $details = parse_url($url);
        return $details['scheme'].'://'.$details['host'];
    }
}
