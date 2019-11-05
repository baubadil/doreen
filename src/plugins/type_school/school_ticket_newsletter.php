<?php

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FinanceAssetTicket
 *
 ********************************************************************/

/**
 *  Ticket subclass for all tickets of the "Newsletter" ticket type.
 *
 *  See \ref ISpecializedTicketPlugin::getSpecializedClassNames() for how specialized
 *  ticket classes work.
 */
class SchoolStudentNewsletter extends Ticket
{
    # Override the static class icon returned by Ticket::getClassIcon().
    protected static $icon = 'newspaper-o';


    /********************************************************************
     *
     *  Instance method overrides
     *
     ********************************************************************/

    /**
     *  Returns miscellanous small bits of information about this ticket. This method is designed
     *  to be overridden by Ticket subclasses so that certain bits of ticket behavior can be modified
     *  if desired.
     *
     */
    public function getInfo($id)
    {
        switch ($id)
        {
            case self::INFO_CREATE_TICKET_TITLE:
            case self::INFO_CREATE_TICKET_BUTTON:
                $cls = $this->getSchoolClassOrThrow()->name;
                return L('{{L//Create and send newsletter for %CLS%}}',
                         [ '%CLS%' => $cls]);

            case self::INFO_CREATE_TICKET_INTRO:
                return L(<<<EOD
<p>{{L/CREATEANDSENDNEWSLETTER/Please enter a title and message for the newsletter and 
press the %{Create}% button. The newsletter will be sent immediately to all parents
of this class. All newsletters are stored here and will be visible to those parents when 
they log in later.}}</p>
EOD
                );

            case self::INFO_DETAILS_TITLE_WITH_TICKET_NO:
            case self::INFO_DETAILS_SHOW_CHANGELOG:
                return FALSE;

            case self::INFO_EDIT_TITLE_HELP:
                return L("{{L//This will also be used as the email subject line.}}");

            case self::INFO_EDIT_DESCRIPTION_LABEL:
                return "{{L//Message}}";
            break;

            case self::INFO_EDIT_DESCRIPTION_HELP:
                return L("{{L//The above will be the contents of the newsletter email. You can use formatting as in a word processor by using the buttons above.}}");

            case self::INFO_EDIT_DESCRIPTION_C_ROWS:
                return 15;
        }

        return parent::getInfo($id);
    }

    /**
     *  Produces a string that is used as the title of the "Edit ticket" form.
     */
    public function makeEditTicketTitle($fHTMLHeading = TRUE)
    {
        return L('{{L//Edit newsletter %TITLE%}}',
                 [ '%S%' => Format::UTF8Quote($this->getTitle()) ]);
    }

    /**
     *  This gets called from ViewTicket for MODE_CREATE and MODE_EDIT to add
     *  an info next to the "submit" button how many users will receive ticket
     *  mail as a result of the change.
     *
     * @return void
     */
    public function addCreateOrEditTicketMailInfo(int $mode,                //!< in: MODE_CREATE or MODE_EDIT,
                                                  HTMLChunk $oHtml)
    {
        if ($mode == MODE_CREATE)
        {
            $oClass = $this->getSchoolClassOrThrow();
            $oGroupParents = $oClass->getParentsGroupOrThrow();
            if ($aParents = $oGroupParents->getMembers(Group::GETUSERFL_ONLY_WITH_LOGIN |  Group::GETUSERFL_ONLY_WITH_EMAIL))
            {
                $cParents = count($aParents);
                $oHtml->addLine(' '.Format::NBSP.Format::NBSP.' '
                                .Ln("{{Ln//This will immediately send the newsletter as email to one parent.//This will immediately send the newsletter as email to %COUNT% parents.}}",
                                    $cParents,
                                    [ '%COUNT%' => $cParents ] ));
            }
        }
        else
            parent::addCreateOrEditTicketMailInfo($mode, $oHtml);
    }

    /**
     *  Builds and sends the ticket notification mail. The mail is sent out in
     *  the last language a user selected or the current system language, when
     *  the user has not set their language yet.
     *
     *  Before calling the parent for regular ticket mail processing (admins
     *  have ACCESS_MAIL for newsletters), we send out the newsletter to all
     *  parents of the group regardless of ACCESS_MAIL settings.
     */
    public function buildAndSendTicketMail(int $mode,
                                           User $oUserChanging,                                         //!< in: user that triggered the ticket mail.
                                           string $introTemplate,                                       //!< in: template for the mail intro.
                                           string $subjectTag,                                          //!< in: subject line to translate.
                                           TicketContext $oContext = NULL,                              //!< in: ticket context, when not provided a new one is generated.
                                           callable $appendBody = NULL)                                 //!< in: callback that takes two in/out strings, the first one is the html body, the second one the plaintext body.
    {
        if ($mode == MODE_CREATE)
        {
            $oClass = $this->getSchoolClassOrThrow();
            $oGroupParents = $oClass->getParentsGroupOrThrow();
            if ($aParents = $oGroupParents->getMembers(Group::GETUSERFL_ONLY_WITH_LOGIN |  Group::GETUSERFL_ONLY_WITH_EMAIL))
            {
                $llEmails = [];
                foreach ($aParents as $id => $oUser)
                    $llEmails[] = $oUser->email;

                $descr = $this->getFieldValue(FIELD_DESCRIPTION);
                $subj = GlobalConfig::GetTicketMailSubjectPrefix().L("{{L//NEWSLETTER}}").' '.$this->getTitle();
                $this->sendTicketMail($oUserChanging,
                                      $subj,
                                      $descr,
                                      Format::HtmlStrip($descr),
                                      $llEmails);
            }
        }

        parent::buildAndSendTicketMail($mode, $oUserChanging, $introTemplate, $subjectTag, $oContext, $appendBody);
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    public function getSchoolClassOrThrow()
        : SchoolClass
    {
        if (!($o = SchoolClass::FindByID($this->getFieldValue(FIELD_PROJECT))))
            throw new DrnException("cannot determine school class ID for ticket #$this->id");
        /** @var SchoolClass $o */
        return $o;
    }
}


