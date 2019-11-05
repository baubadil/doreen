<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Dialog class
 *
 ********************************************************************/

/**
 *  Extension of the HTMLChunk class to generate HTML from Doreen XML dialog resources.
 *
 *  See \ref Load() for the expected format.
 */
class Dialog extends HTMLChunk
{
    const INLINE_SCRIPT_ATTRS = [
        'onClick' => 'click',
        'onMouseEnter' => 'mouseenter'
    ];

    public $dialogClass  = '';
    public $dialogNameId = '';

    /*
     *  Begins a new row in a Bootstrap dialog by emitting a <div ...> with additional fields.
     *  Must be terminated with a matching endRowOrField().
     */
    public function openDlgRow($nameId,         # in: required ID for the active (e.g. input) element contained in the row
                               $rowId = '',     # in: if given, results in an 'id' tag for the entire row, in case your JavaScript needs id
                               $label = '',     # in: if given, results in a <label> element after the <div>
                               $color = '')     //!< in: something like "warning"
    {
        $this->dialogNameId = $nameId;

        if ($color)
            $color = " has-$color";      // In form-group, the color classes must have "has-class" format.
        $this->openDiv($rowId, "form-group$color");

        if ($label)
        {
            $this->append("<label for=\"$nameId\" class=\"col-sm-2 control-label\">$label</label>");
            $this->dialogClass = "col-sm-10";
        }
        else
            # No @label present:
            $this->dialogClass = "col-sm-offset-2 col-sm-10";
    }

    public function openDialogField()
    {
        $this->openDiv(NULL, $this->dialogClass);
    }

    /**
     *  Loads XML dialog data from the given XML file, which should be fully qualified,
     *  and converts it to proper HTML for Bootstrap. Returns a string with that HTML.
     *
     *  Use dirname(__FILE__).'/file.xml' to load an XML file relative to the calling PHP code.
     *
     *  This automatically calls the L() function for automatic translation on the resulting HTML.
     *
     *  The expected format is:
     *
     *  ```xml
            <?xml version='1.0' encoding='UTF-8'?>
            <DLG>
            </DLG>
     *  ```
     *
     *  In between the DLG elements, one can place arbitrary HTML with some extensions. This
     *  can be a simple HTML page that may contain a <form>, or a bootstrap modal.
     */
    static public function Load($dlgfile,                       //!< in: XML file with dialog resource
                                $aValuesForL = NULL,            //!< in: placeholder values for L() (optional)
                                int $indentation = 8)               //!< in: no. of spaces to indent each line with
    {
        $htmlBaseDlgFile = toHTML(basename($dlgfile));

        if (!($fhandle = @fopen($dlgfile, 'rb')))
            throw new DrnException("Failed to open \"$dlgfile\" for reading");
        $xmldata = fread($fhandle, filesize($dlgfile));
        fclose($fhandle);

        return self::Parse($htmlBaseDlgFile,
                           $xmldata,
                           $aValuesForL,
                           $indentation);
    }

    /**
     *  Called by \ref Load() to parse an XML dialog and return it as a string, but can be called
     *  separately too.
     */
    static public function Parse(string $dlgfile,
                                 string $xmldata,
                                 $aValuesForL = NULL,            //!< in: placeholder values for L() (optional)
                                 int $indentation = 8)               //!< in: no. of spaces to indent each line with
        : string
    {
        $reader = new \XMLReader();
        $reader->XML($xmldata);

        $d = new Dialog($indentation);
        $fInScript = FALSE;
        $fInIsset = FALSE;
        $fInIssetValid = TRUE;

        while ($reader->read())
        {
            $name = $reader->name;
            switch ($reader->nodeType)
            {
                case \XMLReader::ELEMENT:
                    $fIsEmpty = $reader->isEmptyElement;
                    $attrs = [];
                    if ($reader->hasAttributes)
                        while ($reader->moveToNextAttribute())
                        {
                            $key = $reader->name;
                            $value = $reader->value;
                            $attrs[$key] = $value;
                        }

                    if ($name == 'DLG')
                        $d->addLine("<!-- Begin dialog $dlgfile -->");
                    else if ($name == 'ROW')
                    {
                        # <ROW @nameId [@rowId] [@label]> will translate into a <div> optionally followed by <label> (if @label is given)
                        #   @nameId: will translate into @for for the following <label> and will be used as @name and @id for the <input> generated by INPUT
                        #   @label will be translated into a <label> that immediately follows
                        #   @rowId can be an optional id only for the row, in case the whole row needs to be addressed
                        #   @color can optionally be something like 'info' or 'warning'.
                        # Within ROW one may use FIELD

                        if (!($nameId = getArrayItem($attrs, 'nameId')))
                            throw new DrnException("Missing @nameId in <$name> in file $dlgfile");
                        if (!($rowId = getArrayItem($attrs, 'rowId')))
                            $rowId = "$nameId-row";
                        $color = getArrayItem($attrs, 'color');
                        $d->openDlgRow($nameId,
                                       $rowId,
                                       getArrayItem($attrs, 'label'),
                                       $color);
                    }
                    else if ($name == 'FIELD')
                    {
                        # FIELD must be within ROW and manages the wide right column depending on whether a @label was specified with ROW
                        # Within FIELD one may use INPUT
                        $d->openDialogField();
                    }
                    else if ($name == 'INPUT')
                    {
                        # <INPUT @type [@placeholder] [@value] [@required]>
                        # INPUT must be within FIELD
                        if (!($type = getArrayItem($attrs, 'type')))
                            throw new DrnException("Missing @type in <$name> in file $dlgfile");

                        $flags = 0;
                        if (getArrayItem($attrs, 'required') == 'yes')
                            $flags |= HTMLChunk::INPUT_REQUIRED;

                        if (getArrayItem($attrs, 'readonly') == 'yes')
                            $flags |= HTMLChunk::INPUT_READONLY;

                        $aAttrs = [];

                        if (array_key_exists('autocomplete', $attrs))
                            $aAttrs[] = "autocomplete=\"{$attrs['autocomplete']}\"";

                        $d->addInput($type,
                                     $d->dialogNameId,
                                     getArrayItem($attrs, 'placeholder'),
                                     getArrayItem($attrs, 'value'),
                                     $flags,
                                     getArrayItem($attrs, 'icon'),
                                     '',
                                     $aAttrs);
                    }
                    else if ($name == 'EDITOR')
                    {
                        $d->addWYSIHTMLEditor($d->dialogNameId);
                    }
                    else if ($name == 'EDITORNOINIT')
                    {
                        $d->addWYSIHTMLEditor($d->dialogNameId,
                                              '',
                                              10,
                                              FALSE);
                    }
                    else if ($name == 'TRIXEDITOR')
                    {
                        $d->addTrixEditor($d->dialogNameId);
                    }
                    else if ($name == 'CHECKBOX')
                    {
                        # <CHECKBOX @label [@checked="yes|no"]>
                        # INPUT must be within FIELD
                        if (!($label2 = getArrayItem($attrs, 'label')))
                            throw new DrnException("Missing @label in <$name> in file $dlgfile");

                        if (!($id = getArrayItem($attrs, 'id')))
                            $id = $d->dialogNameId;

                        $fChecked = FALSE;
                        if (getArrayItem($attrs, 'checked') == 'yes')
                            $fChecked = TRUE;

                        $d->addCheckbox($label2,
                                        $id,
                                        $id,
                                        $id,
                                        $fChecked);         # checked
                    }
                    else if ($name == 'SELECT')
                    {
                        # <SELECT>
                        # INPUT must be within FIELD
                        $d->addSelect($d->dialogNameId, NULL,  NULL, $attrs);
                    }
                    else if ($name == 'HIDDENROW')
                    {
                        if (!($nameId = getArrayItem($attrs, 'nameId')))
                            throw new DrnException("Missing @nameId in <$name> in file $dlgfile");
                        $value = getArrayItem($attrs, 'value');
                        $combineClass = getArrayItem($attrs, 'combineClass');

                        $d->addHidden($nameId,
                                      $value,
                                      $combineClass);
                    }
                    else if ($name == 'SCRIPT')
                    {
                        $fInScript = TRUE;
                    }
                    else if ($name == 'ICON')
                    {
                        if (!($type = getArrayItem($attrs, 'type')))
                            throw new DrnException("Missing @type in <$name> in file $dlgfile");
                        $d->append(Icon::Get($type));
                    }
                    else if ($name == 'PROGRESS')
                    {
                        if (!($id = getArrayItem($attrs, 'id')))
                            $id = $d->dialogNameId;
                        $d->addProgressBar($id, $attrs['class'] ?? NULL, array_key_exists('data-dz-uploadprogress', $attrs));
                    }
                    else
                    {
                        if (    !$fInIsset
                             || ($fInIsset && $fInIssetValid)
                           )
                        {
                            $out = '';
                            if ( $fInIsset && !$fInIssetValid )
                                $out .= ">>";


                            $out .= "<$name";

                            foreach (self::INLINE_SCRIPT_ATTRS as $a => $e)
                            {
                                if (array_key_exists($a, $attrs) && array_key_exists('id', $attrs))
                                {
                                    $id = $attrs['id'];
                                    $onClick = $attrs[$a];
                                    WholePage::AddScript(<<<JS
$('#$id').on('$e', function() { $onClick });
JS
                                    );
                                    unset($attrs[$a]);
                                }
                            }

                            if ($action = $attrs['clickAction'] ?? NULL)
                            {
                                list($component, $action) = explode(':', $action);
                                WholePage::AddJSAction('core', 'dialogClick', [
                                    $attrs['id'],
                                    $component,
                                    $action
                                ]);
                                WholePage::AddPluginChunks($component);
                                unset($attrs['clickAction']);
                            }

                            foreach ($attrs as $key => $value)
                                $out .= " $key=\"$value\"";

                            if ($fIsEmpty)
                                $out .= " /";
                            $out .= ">";

                            if ( $fInIsset && !$fInIssetValid )
                                $out .= "<<";
                            $d->append($out);
                        }
                    }
                break;

                case \XMLReader::END_ELEMENT:
                    if ($name == 'DLG')
                        $d->append("<!-- End dialog $dlgfile -->\n\n");
                    else if (    ($name == 'ROW')
                              || ($name == 'FIELD')
                            )
                        $d->close();
                    else if ($name == 'SCRIPT')
                        $fInScript = FALSE;
                    else if ($name == 'ISSET')
                        $fInIsset = FALSE;
                    else
                        if (    !$fInIsset
                             || ($fInIsset && $fInIssetValid)
                           )
                            $d->append("</$name>");
                break;

                case \XMLReader::TEXT:
                case \XMLReader::WHITESPACE:
                case \XMLReader::SIGNIFICANT_WHITESPACE:
                {
                    $whitespace = $reader->value;
                    $whitespace = str_replace("\n", "\n".$d->getIndentString(), $whitespace);
                    $d->append($whitespace);
                }
                break;

                case \XMLReader::CDATA:
                    if ($fInScript)
                    {
                        $text = $reader->value;
                        replaceMany($text, $aValuesForL);
                        WholePage::AddScript($text);
                    }
                break;
            }
        }
        $reader->close();

        $d->html = str_replace('%ROOTPAGE%', Globals::$rootpage.'/', $d->html);

        # Call L() for translation support.
        return L($d->html, $aValuesForL);
    }
}
