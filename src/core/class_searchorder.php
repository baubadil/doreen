<?php

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

// Ignores FIELD_SCORE currently.


/********************************************************************
 *
 *  SearchOrder class
 *
 ********************************************************************/

class SearchOrder
{
    const TYPE_SCORE = 1;
    const TYPE_FIELD = 2;
    const TYPE_TEMPLATE = 3;
    const TYPE_ID = 4;

    const DIR_ASC = 1;
    const DIR_DESC = 2;

    const SORT_TEXT = 1;
    const SORT_NUMERIC = 2;
    const SORT_JSON_STRINGS = [
        self::SORT_TEXT => 'text',
        self::SORT_NUMERIC => 'num',
    ];

    const SCORE_NAME = 'score';
    const TEMPLATE_NAME = 'template';
    const ID_NAME = 'id';

    /** @var int $type */
    public $type;
    /** @var TicketField|null $oField */
    public $oField = NULL;
    /** @var int $direction */
    public $direction;
    /** @var int $sortType */
    public $sortType;

    function __construct(int $type, int $fieldID = NULL, int $direction = NULL)
    {
        $this->type = $type;
        if ($fieldID !== NULL)
        {
            $this->oField = TicketField::FindOrThrow($fieldID);
            if (!($this->oField->fl & FIELDFL_SORTABLE))
                throw new DrnException("Field ID $fieldID is not sortable");
        }
        $this->direction = $direction;

        $this->setDefaults();
    }

    /**
     *  Sets the default values for unset properties based on other properties.
     *  For example the direction based on the field that is sorted or the sort
     *  type (what kind of data we're sorting) based on the field.
     */
    private function setDefaults()
    {
        if ($this->direction === NULL)
        {
            switch ($this->type)
            {
                case self::TYPE_FIELD:
                    $this->direction = ($this->oField->fl & FIELDFL_DESCENDING) ? self::DIR_DESC : self::DIR_ASC;
                break;
                case self::TYPE_TEMPLATE:
                case self::TYPE_ID:
                case self::TYPE_SCORE:
                    $this->direction = self::DIR_DESC;
                break;
            }
        }

        switch ($this->type)
        {
            case self::TYPE_FIELD:
                $this->sortType = ($this->oField->fl & FIELDFL_TYPE_TEXT_NATURAL) ? self::SORT_TEXT : self::SORT_NUMERIC;
            break;
            case self::TYPE_TEMPLATE:
            case self::TYPE_ID:
            case self::TYPE_SCORE:
                $this->sortType = self::SORT_NUMERIC;
            break;
        }
    }

    /**
     *  Label name for this sort
     *
     *  @return string
     */
    public function getName()
        : string
    {
        if ($this->type == self::TYPE_SCORE)
            return L("{{L//Relevance}}");
        else if ($this->type == self::TYPE_TEMPLATE)
            return L("{{L//Template}}");
        else if ($this->type == self::TYPE_ID)
            return L("{{L//ID}}");
        return FieldHandler::Find($this->oField->id)->getLabel()->html;
    }

    /**
     *  Name of this sort when an URL param.
     *
     *  @return string
     */
    public function getParam()
        : string
    {
        if ($this->type == self::TYPE_SCORE)
            return self::SCORE_NAME;
        else if ($this->type == self::TYPE_TEMPLATE)
            return self::TEMPLATE_NAME;
        else if ($this->type == self::TYPE_ID)
            return self::ID_NAME;
        return $this->oField->name;
    }

    /**
     *  URL parameter value including sort direction for this sort. Reverse of
     *  \ref SearchOrder::FromParam.
     *
     *  @return string
     */
    public function getFormattedParam()
        : string
    {
        return ($this->direction === self::DIR_ASC ? '' : '!').$this->getParam();
    }

    /**
     *  JSON serialized version for API responses.
     *
     *  @return array
     */
    public function toJSON()
        : array
    {
        return [
            'param' => $this->getParam(),
            'name' => $this->getName(),
            'direction' => $this->direction == self::DIR_ASC ? 0 : 1,
            'type' => self::SORT_JSON_STRINGS[$this->sortType],
        ];
    }

    /**
     *  Constructs an instance based on the sort parameter value. Reverse of
     *  \ref SearchOrder::getFormattedParam.
     *
     *  @return SearchOrder
     */
    public static function FromParam(string $param)
        : SearchOrder
    {
        $dir = self::DIR_ASC;
        if ($param[0] === '!')
        {
            $dir = self::DIR_DESC;
            $param = substr($param, 1);
        }

        $type = self::TYPE_FIELD;
        $fieldID = NULL;
        if ($param == self::SCORE_NAME)
            $type = self::TYPE_SCORE;
        else if ($param == self::TEMPLATE_NAME)
            $type = self::TYPE_TEMPLATE;
        else if ($param == self::ID_NAME)
            $type = self::TYPE_ID;
        else
            $fieldID = TicketField::FindByName($param)->id;

        return new self($type, $fieldID, $dir);
    }

    /**
     *  Constructs a SearchOrder based on the array of filters given. By default
     *  we sort by created date, but if it's a full text search, we sort by score.
     *
     *  @return SearchOrder
     */
    public static function FromFilters(array $aFilters)
        : SearchOrder
    {
        /** @var SearchFilter $oFilter */
        foreach ($aFilters as $oFilter)
        {
            if($oFilter->type == SearchFilter::TYPE_FULLTEXT)
                return new self(self::TYPE_SCORE);
        }
        return new self(self::TYPE_FIELD, FIELD_CREATED_DT);
    }

    /**
     *  Returns all possible orderings (ignoring directions) for the given
     *  ticket types. Only includes score when a full text search was performed.
     *
     *  @return SearchOrder[]
     */
    public static function GetAll(array $aTicketTypes,      //!< in: Array of TicketTypes to look for sortable fields in.
                                  bool $fFulltext = FALSE)  //!< in: if a full text search was performed.
        : array
    {
        $aVisibleFields = TicketType::GetManyVisibleFields($aTicketTypes,
                                                           TicketType::FL_INCLUDE_CORE);      # flags: include neither children nor hidden fields nor details
        $aOrderBys = [];

        /** @var TicketField $oField */
        foreach ($aVisibleFields as $field_id => $oField)
            if ($oField->fl & FIELDFL_SORTABLE)
                $aOrderBys[] = new self(self::TYPE_FIELD, $field_id);

        if ($fFulltext)
            $aOrderBys[] = new self(self::TYPE_SCORE);

        return $aOrderBys;
    }
}
