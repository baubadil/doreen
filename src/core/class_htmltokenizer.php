<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


class TokenChunk
{
    public $chunk;
    public $type;
    const TYPE_HTML = 1;
    const TYPE_TEXT = 2;

    public function __construct($chunk, $type)
    {
        $this->chunk = $chunk;
        $this->type = $type;
    }
}

/**
 *  Helper class that splits elements from non-elements in HTML (or XML) strings.
 *
 *  The constructor looks at the given string and builds a flat list of TokenChunk
 *  objects with the HTML and non-HTML chunks, marking them accordingly.
 */
class HTMLTokenizer
{
    /** @var TokenChunk[] $llChunks */
    public $llChunks = [];

    public function __construct($htmlData)
    {
//        Debug::Log(0, "HTMLTOKENIZER htmlData = $htmlData");

        $len = strlen($htmlData);

        $pLast = 0;
        while (($pOpen = strpos($htmlData, '<', $pLast)) !== FALSE)
        {
            if ($pOpen > $pLast)
                $this->llChunks[] = new TokenChunk(substr($htmlData, $pLast, $pOpen - $pLast), TokenChunk::TYPE_TEXT);

//            Debug::Log(0, "HTMLTOKENIZER pOpen=$pOpen");
            $pClose = strpos($htmlData, '>', $pOpen + 1);

            $htmlChunk = substr($htmlData, $pOpen, $pClose - $pOpen + 1);

            $this->llChunks[] = new TokenChunk($htmlChunk, TokenChunk::TYPE_HTML);

            $pLast = $pClose + 1;
        }

        if ($pLast < $len)
            $this->llChunks[] = new TokenChunk(substr($htmlData, $pLast), TokenChunk::TYPE_TEXT);

//        Debug::Log(0, "HTMLTOKENIZER ".print_r($this->llChunks, TRUE));
    }

}


/*

  Test <b>WITH</b> formatting
  0123456789
       ^pOpen = 5
         ^pClose = 7

*/