<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

/********************************************************************
 *
 *  AcctContractorHandler
 *
 ********************************************************************/

/**
 *  Field handler for FIELD_STUDENT ('school_student').
 *
 *  This uses ticket_vcards to link N contacts (VCard tickets) to the owning
 *  ticket. The field has FIELDFL_ARRAY set and can thus use multiple rows
 *  in ticket_ints.
 *
 *  Spec:
 *
 *   -- Can be NULL.
 *
 *   -- In Ticket instance: PHP flat list of integer Doreen user IDs.
 *
 *   -- GET/POST/PUT JSON data: JSON array of either existing user IDs
 *      or new users to be created. writeToDatabase() can create users
 *      if necessary and permitted.
 *
 *   -- Database: rows in ticket_ints (FIELDFL_ARRAY).
 *
 *   -- Search engine: TODO.
 */
class SchoolParentsHandler extends FieldHandler
{
    public $label = "{{L//Parents}}";


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    public function __construct()
    {
        parent::__construct(FIELD_STUDENT_PARENTS);
    }


    /********************************************************************
     *
     *  Field data serialization
     *
     ********************************************************************/

    /**
     *  This gets called by \ref writeToDatabase() for every field before the
     *  new value gets written into the database. This must return a value that
     *  fits into the value column of the field's database table, or, in the
     *  case of FIELDFL_ARRAY, an arra of those values. It can also throw a
     *  APIException with the field name if validation fails.
     *
     *  We override this to process the complex JSON data we get from the front-end
     *  in POST/PUT data.
     *
     *  With oldValue, we get a string with comma-separated integers for the Doreen
     *  user IDs.
     *
     *  With newValue, here we get the JSON data from the front-end which is a
     *  serialized array of ParentForJson instances.
     *
     * @return mixed
     */
    public function validateBeforeWrite(TicketContext $oContext,
                                        $oldValue,
                                        $newValue)
    {
        $llUserIdsToWrite = [];

        // Decode to an array, not to objects, so we can validate the fields.
        if (NULL === ($aFromJson = json_decode($newValue, TRUE)))
            throw new APIException($this->fieldname, "Invalid input format", 400, print_r($newValue, TRUE));

        $oStudent = SchoolStudentTicket::AssertStudent($oContext->oTicket);
        $oClass = $oStudent->getSchoolClassOrThrow();
        $oGroupParents = $oClass->getParentsGroupOrThrow();

        // We call the parent with this array as newValue, which will then do the
        // standard array handling by adding / removing rows to / from ticket_ints.
        if (!is_array($aFromJson))
            // For now, accept a list of existing user IDs as a simple comma-separated list of ints.
            foreach (explode(',', $aFromJson) as $uid)
            {
                self::ValidateParent($oGroupParents, $uid);
                $llUserIdsToWrite[] = $uid;
            }
        else
            foreach ($aFromJson as $aOne)
            {
                $oParentForJson = ParentForJson::LoadFromArrayOrThrow($aOne);

                $uid = (int)$oParentForJson->uid;
                $oUserUpdate = $oUser = NULL;
                if (ParentForJson::USER_CREATE === $uid)
                {
                    # A zero user ID means that the front-end wants us to create a new Doreen user.

                    // If the email has already been used as a login name, reuse that user.
                    if ($oUser = User::FindByLogin($oParentForJson->email))
                    {
                        $oUserUpdate = $oUser;

                        // This might be a parent of another child in another class so add them here too.
                        $oUser->addToGroup($oGroupParents);
                    }
                    else
                        $oUser = $oClass->createUserForParent($oParentForJson->email,
                                                              $oParentForJson->longname,
                                                              $oParentForJson->mobile,
                                                              TRUE); // TODO maybe make this configurable

                    $uid = $oUser->uid;
                }
                else
                    $oUserUpdate = self::ValidateParent($oGroupParents, $uid);

                if ($oUserUpdate)
                {
                    // Do not allow a rep to modify admin or guru user accounts.
                    if (    ($oUserUpdate->isAdmin())
                         || ($oUserUpdate->isGuru())
                       )
                        throw new NotAuthorizedException();

                    if (    ($oParentForJson->longname != $oUserUpdate->longname)
                         || ($oParentForJson->email != $oUserUpdate->email)
                       )
                    {
                        $oUserUpdate->update(NULL,
                                             $oParentForJson->longname,
                                             $oParentForJson->email,
                                             TRUE);
                    }

                    // In any case (new or old user), set the mobile extra data.
                    $oUserUpdate->setKeyValue(ParentForJson::USER_EXTRA_MOBILE,
                                              $oParentForJson->mobile,
                                              FALSE);
                }

                $llUserIdsToWrite[] = $uid;
            }

        return $llUserIdsToWrite;
    }

    private static function ValidateParent(Group $oGroupParents,
                                           $uid)
        : User
    {
        if (    ($oUser = User::Find($uid))
             && (    ($oUser->isMember($oGroupParents->gid))
                  || ($oUser->isAdmin())
                )
           )
            return $oUser;

        throw new DrnException("Given user $uid does not exist or is not a parent of this class");
    }

    /**
     *  Called by \ref Ticket::toArray() to give each field handler a chance to add meaningful
     *  values to the JSON array returned from there.
     *
     *  If the $paFetchSubtickets reference is not NULL, it points to an array in
     *  \ref Ticket::GetManyAsArray(), and this function can add key/subkey/value pairs to that
     *  array to instruct that function to fetch additional subticket data.
     *  The format is: $paFetchSubtickets[idParentTicket][stringKey] = list of sub-ticket IDs.
     *  \ref Ticket::GetManyAsArray() will then fetch JSON for the sub-ticket IDs and add their JSON
     *  data to $aJSON in one go.
     *
     *  Our ticket data is an array of Doreen user IDs; instead, we push into the Json array an
     *  array of ParentForJson objects, which will then get serialized.
     *
     * @return void
     */
    public function serializeToArray(TicketContext $oContext,
                                     &$aReturn,
                                     $fl,
                                     &$paFetchSubtickets)
    {
        $a = [];
        if ($value = $this->getValue($oContext))
            foreach ($value as $uid)
                if ($uid)
                    $a[] = ParentForJson::LoadUser($uid);
        $aReturn += [ $this->fieldname => $a ];
    }

    /**
     *  This must return a plain-text formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *
     *  The value is a comma-separated list of Doreen user ID, so format that a bit more nicely.
     */
    public function formatValuePlain(TicketContext $oContext,    //!< in: ticket page context
                                     $value)                     //!< in: value to format by this field handler
    {
        $aUsers = [];
        if ($value && is_array($value))
        {
            /** @var int[] $aUserIds */
            $aUserIds = $value;
            foreach ($value as $uid)
                if ($oUser = User::Find($uid))
                    $aUsers[] = $oUser->longname;
        }

        return implode(", ", $aUsers);
    }

    /**
     *  This must return a HTML formatting of the given value. $oContext allows for
     *  inspecting the context in which this is called, should that be necessary.
     *  This must NOT return NULL, ever.
     *
     *  This default implementation calls \ref formatValuePlain(), HTML-escapes the
     *  result and puts it into a new HTMLChunk.
     *
     *  If you need to override these methods to display human-readable strings for
     *  internal codes or enumeration values, you need to at least override
     *  formatValuePlain().
     *
     *  Whether you also need to override this method depends on whether you want
     *  to use fancy HTML like links in your values. If not, this default
     *  implementation looks at $fLinkifyValue, $fShowNoData and $fHighlightSearchTerms
     *  in $this. So it may be enough to override formatValuePlain() and set those
     *  variables to FALSE in your FieldHandler subclass.
     *
     *  @return HTMLChunk
     */
    public function formatValueHTML(TicketContext $oContext,    //!< in: ticket page context
                                    $value)                     //!< in: value to format by this field handler
        : HTMLChunk
    {
        if (    ($fGrid = ($oContext->mode == MODE_READONLY_GRID))
             || ($oContext->mode == MODE_READONLY_LIST)
             || ($oContext->mode == MODE_READONLY_DETAILS)
           )
        {
            $aUsers = [];
            if ($value && is_array($value))
            {
                /** @var int[] $aUserIds */
                $aUserIds = $value;
                foreach ($value as $uid)
                    if ($oUser = User::Find($uid))
                    {
                        $o2 = HTMLChunk::FromString($oUser->longname);
                        if ($oUser->email)
                        {
                            $o2->append(' &lt;'
                                       .HTMLChunk::MakeElement('a',
                                                   [ 'href' => "mailto:$oUser->email" ],
                                                   HTMLChunk::FromString($oUser->email))->html
                                        .'&gt;');
                        }
                        $aUsers[] = $o2;
                    }
            }

            $o = HTMLChunk::Implode("<br>", $aUsers);

            if ($fGrid)
                $this->prefixDescription($o);
        }
        else
        {
            $o = parent::formatValueHTML($oContext, $value);
        }

        return $o;
    }


    /********************************************************************
     *
     *  Field editor
     *
     ********************************************************************/

    /**
     *  Called from \ref appendFormRow() to produce the label column on the left side of
     *  the form for this field.
     *
     *  Subclasses may want to override this for adding additional things to the label,
     *  similar to what \ref addReadOnlyLabelColumn() can do for read-only rows.
     *
     * @return void
     */
    public function addFormLabelColumn(TicketPageBase $oPage,   //!< in: TicketPageBase instance with ticket dialog information
                                       string $idControl,
                                       string $gridclass,
                                       string $extraClasses)
    {
        $oHtmlLabel = $this->getLabel($oPage);
        $htmlAddAnother = Icon::Get('add-another');
        $help = L("{{L//Add another parent}}");
//        return ;
        $oHtmlLabel->append("<br><a href='#' id='$idControl-add-parent' class='btn btn-primary' title='$help'>$htmlAddAnother</a>");
        $oPage->oDlg->appendElement('label',
                                    [ 'for' => $idControl,
                                      'class' => "control-label col-sm-2 $extraClasses" ],
                                    $oHtmlLabel);
    }

    /**
     *  This gets called in the details view of MODE_CREATE or MODE_EDIT by
     *  \ref appendFormRow() to add an HTML form field  to the details dialog
     *  that allows the user to edit this field for a ticket.
     *
     *  We add HTML for editing controls, but we also add a (hidden) text entry
     *  field with the dialog control, whose contents are assembled by the
     *  Typescript front-end code, and which is the only thing that gets sent.
     *
     * @return void
     */
    public function addDialogField(TicketPageBase $oPage,          //!< in: TicketPageBase instance with ticket dialog information
                                   $idControl)      //!< in: HTML ID to use for the control (has been used with the preceding label)
    {
        $aJson = [];

        $oPage->oDlg->openDiv("$idControl-div");

        $oPage->oDlg->openGridRow("$idControl-row-template", "hidden");        # for typescript magic

        $oPage->oDlg->openDiv(NULL, "$idControl-uid");
        $oPage->oDlg->addInput("hidden", NULL, '', '');
        $oPage->oDlg->close(); // column

        self::AddColumnsEmailLongnameMobile($oPage->oDlg, $idControl);

        $oPage->oDlg->openGridColumn(1, NULL);
        $oPage->oDlg->appendElement('a',
                                    [ 'class' => "btn btn-primary $idControl-delete",
                                      'title' => L("{{L//Remove this parent}}") ],
                                    Icon::GetH('trash'));
        $oPage->oDlg->close(); // column
        $oPage->oDlg->close();  // row

        /** @var int[] $value */
        if ($value = $this->getValue($oPage))
        {
            foreach ($value as $uid)
            {
                $oparentForJson = ParentForJson::LoadUser($uid);
                $aJson[] = $oparentForJson;
            }
        }

        $oPage->oDlg->openDiv("$idControl-playground");        # for typescript to insert into
        $oPage->oDlg->close();

        $oPage->oDlg->close();  // idcontrol-div

        // Entry field.
        $oPage->oDlg->addInput("hidden",
                               $idControl,
                               '',
                               toHTML(json_encode($aJson)));

        $oPage->oDlg->addHelpPara(HTMLChunk::FromEscapedHTML(L(<<<HTML
{{L/SCHOOLNEWPARENT/
Here you can add parent names, emails and mobile phone numbers for the student.
You can leave rows empty and add and remove rows as you like by pressing the buttons on the left and right.
<br><b>Note:</b> For each parent email you add here, an invitation will be sent to that parent when you submit the student data so that they can log in.}}
HTML
            )));

        WholePage::AddTypescriptCallWithArgs(SCHOOL_PLUGIN_NAME, /** @lang JavaScript */ 'school_initParentsEditor',
                                             [ $idControl ] );
    }

    public static function AddColumnsEmailLongnameMobile(HTMLChunk $oHtml,
                                                         string $idControl)
    {
        $oHtml->openGridColumn(4, NULL, "$idControl-email");
        $oHtml->addInput("text", "$idControl-email", L("{{L//Email}}"), '', 0, 'mail');
        $oHtml->close(); // column
        $oHtml->openGridColumn(4, NULL, "$idControl-longname");
        $oHtml->addInput("text", "$idControl-longname", L("{{L//Full name}}"), '', 0, 'realname');
        $oHtml->close(); // column
        $oHtml->openGridColumn(3, NULL, "$idControl-mobile");
        $oHtml->addInput("text", "$idControl-mobile", L("{{L//Phone (optional)}}"), '', 0, 'phone');
        $oHtml->close(); // column
    }
}

/**
 *  Helper class for serializing / unserializing parents of a school student
 *  into / from JSON. Instances of this are directly passed to json_encode
 *  without validation, so do not add additional members and be careful what
 *  data is revealed here!
 */
class ParentForJson
{
    const USER_CREATE = 0;      # Zero (not NULL!) means create new
    public $uid;        # Doreen user ID or USER_CREATE
    public $email;      # also used as login
    public $longname;
    public $mobile;

    const USER_EXTRA_MOBILE = 'school_phone';

    /**
     *  Factory method that creates an instance from a Doreen user ID.
     */
    public static function LoadUser($uid)
        : self
    {
        if (!($oUser = User::Find($uid)))
            throw new DrnException("Invalid user ID $uid");

        $o = new self();
        $o->uid = (int)$uid;
        $o->email = $oUser->email;
        $o->longname = $oUser->longname;
        $o->mobile = $oUser->getExtraValue(self::USER_EXTRA_MOBILE,
                                           '');
        return $o;
    }

    /**
     *  Factory method that creates an instance from a key/value pairs array.
     *  Throws if keys are missing.
     */
    public static function LoadFromArrayOrThrow(array $a)
    {
        $o = new self();
        foreach ([ 'uid', 'email', 'longname', 'mobile' ] as $key)
        {
            if (NULL === ($v = $a[$key] ?? NULL))
                throw new DrnException("missing key \"$key\" in parent data");
            $o->$key = $v;
        }
        $o->uid = (int)$o->uid;
        return $o;
    }
}
