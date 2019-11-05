<?php

namespace Doreen;


/**
 *  Helper class intended to make using XMLReader (the PHP XML reader based on expat) less verbose.
 *
 *  PHP has many XML implementations. XMLReader is a rather raw one that is based on expat, and
 *  requires quite a bit of code before it does anything useful. Its main advantage over SimpleXML
 *  and others is however that it can parse XML while loading ("stream-parsing") and does not
 *  require loading an entire document into memory before beginning to parse.
 *
 *  DrnXML simplifies this process by allowing for specifying a callback function which gets
 *  called on every element as they come along.
 *
 *  Do not construct objects of this class manually (the constructor is private). Instead, call the
 *  static methiods LoadFromFile() or ParseString(), which will construct an instance as needed.
 *
 *  Example:
 *
        DrnXML::ParseString($xml,
                            function($oXML) use(&$count)
                            {
                                if ($oXML->name === 'count')
                                    if ($attrs = $oXML->getAttributes())
                                        $count = getArrayItem($attrs, 'length');
                            });

 */
class DrnXML extends \XMLReader
{
    /** @var  Callable */
    private $fnOnElement;
    /** @var  Callable */
    private $fnOnEndElement;

    public $aUser = [];                # user data

    private function __construct($fnOnElement,
                                 $fnOnEndElement = NULL)
    {
        $this->fnOnElement = $fnOnElement;
        $this->fnOnEndElement = $fnOnEndElement;
    }

    public function getAttributes()
    {
        $aAttrs = [];
        if ($this->hasAttributes)
            while ($this->moveToNextAttribute())
            {
                $key = $this->name;
                $value = $this->value;
                $aAttrs[$key] = $value;
            }

        return $aAttrs;
    }

    public function drnParse()
    {
        while ($this->read())
        {
            switch ($this->nodeType)
            {
                case \XMLReader::ELEMENT:
                    if ($pfn = $this->fnOnElement)
                        $pfn($this);
                break;

                case \XMLReader::END_ELEMENT:
                    if ($pfn = $this->fnOnEndElement)
                        $pfn($this);
                break;
            }
        }
    }

    /**
     *  Static factory method that constructs a DrnXML to load XML from the given file and calls the given callbacks.
     */
    public static function LoadFromFile($filename,
                                        $fnOnElement,               //!< in: called for every opening element tag
                                        $fnOnEndElement = NULL)     //!< in: called for every closing element tag
    {
        $reader = new DrnXML($fnOnElement, $fnOnEndElement);
        if (!($reader->open($filename)))
            throw new DrnException("Failed to read XML file $filename");
        $reader->drnParse();
        $reader->close();

        return $reader->aUser;
    }

    /**
     *  Static factory method that constructs a DrnXML to parse the given string as XML and calls the given callbacks.
     */
    public static function ParseString($xmldata,
                                       $fnOnElement,                //!< in: called for every opening element tag
                                       $fnOnEndElement = NULL,
                                       $encoding = NULL)            //!< in: explicit encoding or NULL for default from either XML or UTF-8 if none
    {
        if (!$xmldata)
            throw new DrnException("Empty XML data");

        $reader = new DrnXML($fnOnElement, $fnOnEndElement);
        if (!($reader->xml($xmldata,
                           $encoding)))
            throw new DrnException("Failed to parse XML");
        $reader->drnParse();
        $reader->close();

        return $reader->aUser;
    }
}
