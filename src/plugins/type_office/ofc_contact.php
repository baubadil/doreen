<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

/********************************************************************
 *
 *  OfcContact
 *
 ********************************************************************/

/**
 *  An OfcContact represents a single row from the ofc_contacts table. An array
 *  of these with 0 or more items can be stored in each office file ticket.
 *
 *  An array of these is stored in OfcContactsList.
 */
class OfcContact extends ManagedTable
{
    static protected $tablename = 'ofc_contacts';
    static protected $llFields  = [
        'ticket_id',
        'field_id',
        'vcard_id',
        'contact_type',
        'fileno',
        'parent_id'
    ];

    const TYPE_SUPPLIER = 1;
    const TYPE_CUSTOMER = 2;
    const TYPE_CLIENT = 3;
    const TYPE_OPPONENT = 4;
    const TYPE_REPRESENTATIVE = 5;

    public $ticket_id;
    public $field_id;
    public $vcard_id;           # Ticket ID of VCard
    public $contact_type;       # TYPE_* constant
    public $fileno;             # optional string or NULL
    /** @var OfcContact $oParent | NULL */
    public $parent_id;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Creates a detached instance, which has NEITHER an ID NOR an owning
     *  ticket. This must only be used if OfcContactsList::toDatabase()
     *  is called immediately afterwards to write the contacts to the database.
     */
    public function __construct(int $rowid = NULL,
                                Ticket $oOwner = NULL,
                                int $vcard_id,
                                int $contact_type,
                                $fileno,
                                $parent_id)
    {
        $this->ticket_id = ($oOwner) ? $oOwner->id : NULL;
        $this->field_id = FIELD_OFFICE_CONTACTS;
        $this->vcard_id = $vcard_id;
        $this->contact_type = $contact_type;
        $this->fileno = $fileno;
        $this->parent_id = $parent_id;
    }


    /********************************************************************
     *
     *  Public instance methods
     *
     ********************************************************************/

    public function getVcardOrThrow(User $oUser)
        : VCardTicket
    {
        $o = Ticket::FindForUser($this->vcard_id,
                                 $oUser,
                                 ACCESS_READ);
        if (!($o instanceof VCardTicket))
            throw new DrnException("ticket #$this->vcard_id is not a VCard");

        /** @var VCardTicket $o */
        return $o;
    }

    public function formatHtml(User $oUser,
                               bool $fDetails)
        : HTMLChunk
    {
        $oVCard = $this->getVcardOrThrow($oUser);

        $oHtml = $oVCard->formatHtml($fDetails);

        $html = '<b>'.self::DescribeContactType($this->contact_type).":</b> "
               .$oVCard->makeLink();
        if ($fDetails)
            $html .= "<br>";
        else
            $html .= ", ";
        $oHtml->prepend($html);

        if ($this->fileno)
            $oHtml->append(($fDetails ? "<br>" : ' '.Format::MDASH.' ')
                           .'<b>'
                           .L('{{L//File number}}').': '
                           .toHTML($this->fileno)
                           .'</b>');

        return $oHtml;
    }

    public static function DescribeContactType($t)
        : string
    {
        switch ($t)
        {
            case self::TYPE_SUPPLIER:
                return L("{{L//Supplier}}");

            case self::TYPE_CUSTOMER:
                return L("{{L//Customer}}");

            case self::TYPE_CLIENT:
                return L("{{L//Client}}");

            case self::TYPE_OPPONENT:
                return L("{{L//Opponent}}");

            case self::TYPE_REPRESENTATIVE:
                return L("{{L//Representative}}");
        }
    }

}

/**
 *  Value of the contacts field.
 *
 *  Contacts array has three representations:
 *
 *   -- Rows in ofc_contacts, with i, ticket_id, contact_type, fileno, parent fields,
 *      where ticket_id is the same for all of them.
 *
 *   -- In memory: one instance of this class, containing an array of OfcContact instances.
 *      This is the ticket field value for FIELD_OFFICE_CONTACTS.
 *
 *      From rows (above) into memory: OfcContactsList constructor ($idTicket).
 *
 *      From memory back into database (above): OfcContact::write().
 *      (This deletes all contact rows of the ticket and writes them new.)
 *
 *   -- As JSON between front-end and back-end. Format must be defined.
 *
 *      From memory to JSON: OfcContactsList::toJson().
 *
 *      From JSON to memory: OfcContactsList::fromJson().
 *
 *   -- In GUI, as a list of dialog items.
 *
 */
class OfcContactsList
{
    /** @var OfcContact[] $aContacts */
    public $aContacts = [];

    public function addContact(OfcContact $oContact)
    {
        $this->aContacts[] = $oContact;
    }

    public static function ClearOld(Ticket $oTicket)
    {
        Database::DefaultExec(<<<SQL
DELETE FROM ofc_contacts
WHERE ticket_id = $1
SQL
            , [ $oTicket->id ] );
    }

    public function toDatabase(Ticket $oTicket,
                               bool $fClearOld)
    {
        if ($fClearOld)
            self::ClearOld($oTicket);

        $llData = [];
        foreach ($this->aContacts as $oContact)
        {
            $oContact->ticket_id = $oTicket->id;

            array_push($llData,
                       $oTicket->id,
                       FIELD_OFFICE_CONTACTS,
                       $oContact->vcard_id,
                       $oContact->contact_type,
                       $oContact->fileno,
                       $oContact->parent_id);
        }

        Database::GetDefault()->insertMany('ofc_contacts',
                                           [ 'ticket_id',
                                             'field_id',
                                             'vcard_id',
                                             'contact_type',
                                             'fileno',
                                             'parent_id' ],
                                           $llData);

        # TODO this leaves the objects' 'id' members uninitialized!
    }
}
