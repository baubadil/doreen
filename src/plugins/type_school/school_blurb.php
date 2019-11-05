<?php
/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  FTBlurb
 *
 ********************************************************************/

/**
 *  Title page functionality for the School plugin. See the Blurb class for how this works.
 */
class SchoolBlurb extends Blurb
{
    /**
     *  Must return a pair of strings with ID and descriptive name for the blurb.
     *  The ID is stored in GlobalConfig, the descriptive name is displayed
     *  in the global settings.
     */
    public function getID()
    {
        return [ 'school_intro', 'School plugin main page items' ];
    }

    private static function MakeButton(SchoolClass $oClass,
                                       $lstr,
                                       $href,
                                       $color = 'primary')
        : HTMLChunk
    {
        return HTMLChunk::MakeElement('a',
                                      [ 'href' => $href,
                                        'class' => "btn btn-$color",
                                      ],
                                      HTMLChunk::FromString(L($lstr,
                                                              [ '%CLS%' => Format::UTF8Quote($oClass->name) ] )));
    }

    /**
     *  Called by the Doreen main page code to give plugins a chance to add HTML to the main page.
     *  To do so, the plugin can add to the given HTMLChunk instance.
     */
    public function emit(&$htmlTitle,
                         HTMLChunk $oHTML)
    {
        $htmlTitle = L("{{L//Welcome!}}");

        if (!($oUserLoggedIn = LoginSession::IsUserLoggedIn()))
            $oHTML->addPara(L("{{L//Please go to the menu at the top right of the screen and log in with your user name and password.}}"));
        else
        {
            // Two steps: 1) find all student templates that the currently logged in user can see;
            //            2) load the school classes for them.

            $idStudentType = GlobalConfig::Get(PluginSchool::SCHOOL_STUDENT_TYPE_ID_CONFIGKEY);
            $idNewsletterType = GlobalConfig::Get(PluginSchool::SCHOOL_NEWSLETTER_TYPE_ID_CONFIGKEY);
            $oAccess = Access::GetForUser($oUserLoggedIn);
            $cPrinted = 0;
            if ($fr = Ticket::FindMany( [ SearchFilter::FromTicketTypes([ $idStudentType ]),
                                          SearchFilter::Templates(),
                                          SearchFilter::FromACLIDs($oAccess->getACLsForAccess(ACCESS_READ))
                                        ],
                                        'title'))
            {
                Ticket::PopulateMany($fr->aTickets, Ticket::POPULATE_DETAILS);

                $llStudentTemplateIds = [];

                foreach ($fr->aTickets as $idTicket => $oTicket)
                    if ($oTicket instanceof SchoolStudentTicket)
                        $llStudentTemplateIds[] = $idTicket;

                // Step 2: list all the classes this user has access to, sorted alphabetically by class name.
                SchoolClass::LoadAll(NULL, NULL, 'name');
                if ($llClasses = SchoolClass::GetAllForField(FIELD_SCHOOL_CATEGORY_CLASS))
                {
                    /** @var  $oClass SchoolClass */
                    foreach ($llClasses as $idClass => $oClass)
                    {
                        // These have already been populated.
                        $oStudentTemplate = $oClass->getStudentTemplateOrThrow();
                        $oNewsletterTemplate = $oClass->getNewsletterTemplateOrThrow();

                        $oHTML->openGridRow();

                        $oHTML->addGridColumn(2, toHTML($oClass->name), NULL, 'lead');

                        $href = WebApp::MakeLinkWithArgs('/tickets',
                                                         [ 'sortby' => 'title',
                                                           'format' => 'grid',
                                                           'drill_type' => $idStudentType,
                                                           'drill_school_class' => $idClass ]);
                        if (ACCESS_READ & $oStudentTemplate->getUserAccess($oUserLoggedIn))
                            $oHTML->addGridColumn(3,
                                                  self::MakeButton($oClass,
                                                                   "{{L//Show students}}",
                                                                   $href,
                                                                   'info')->html,
                                                  NULL,
                                                  'text-center');

                        if ($oStudentTemplate->canCreate($oUserLoggedIn))
                            $oHTML->addGridColumn(3,
                                                  self::MakeButton($oClass,
                                                                   "{{L//Create new student}}",
                                                                   WebApp::MakeUrl('/newticket/'.$oStudentTemplate->id),
                                                                   'danger')->html,
                                                  NULL,
                                                  'text-center');

                        if ($oNewsletterTemplate->canCreate($oUserLoggedIn))
                            $oHTML->addGridColumn(3,
                                                  self::MakeButton($oClass,
                                                                   "{{L//Write newsletter}}",
                                                                   WebApp::MakeUrl('/newticket/'.$oNewsletterTemplate->id),
                                                                   'warning')->html,
                                                  NULL,
                                                  'text-center');

                        $oHTML->close(); // grid row

                        ++$cPrinted;
                    }
                }
            }

            if (!$cPrinted)
            {
                $oHTML->addAlert(L("{{L//This installation is not yet very useful, no school classes have been defined yet.}}"), NULL, 'alert-warning');
            }

            if (LoginSession::IsCurrentUserAdmin())
            {
                $oHTML->addPara('<a href="'.WebApp::MakeUrl('/tickets').'">Show all tickets</a>');

                $idDialog = 'create-class';

                $strCreateNewClass = L("{{L//Create new class}}");

                $oHTML->addShowHideButton("$idDialog-div",
                                          $strCreateNewClass,
                                          L('{{L//Hide}}'),
                                          'btn-primary');

                $oHTML->openDiv("$idDialog-div", 'well hidden');

                $oHTML->openForm($idDialog);

                $oHTML->openFormRow();
                $oHTML->addLabelColumn(HTMLChunk::FromString(L("{{L//Class name}}")));
                $oHTML->openWideColumn();
                $oHTML->addInput('text', "$idDialog-name");
                $oHTML->close();     # wide column
                $oHTML->close();     # form row

                $oHTML->openFormRow();
                    $oHTML->addLabelColumn(HTMLChunk::FromString(L("{{L//Parent representative}}")));
                    $oHTML->openWideColumn();
                        $oHTML->openGridRow();
                            SchoolParentsHandler::AddColumnsEmailLongnameMobile($oHTML, $idDialog);
                        $oHTML->close();     # grid row
                        $oHTML->openGridRow();
                            $oHTML->openGridColumn(12);
                                $oHTML->addHelpPara(HTMLChunk::FromString(L(
                                    "{{L//If no user account exists yet for the given email, this will create a new one and send an invitation to the given e-mail address so that the new user can log in.}}"
                                                                          )));
                            $oHTML->close();     # grid row
                        $oHTML->close();     # grid row
                    $oHTML->close();     # wide column
                $oHTML->close();     # form row

                $oHTML->addErrorAndSaveRow($idDialog,
                                           HTMLChunk::FromString($strCreateNewClass));

                $oHTML->close(); // form

                $oHTML->close(); // well div

                WholePage::AddTypescriptCallWithArgs(SCHOOL_PLUGIN_NAME, /** @lang JavaScript */'school_initCreateClass',
                                                     [ $idDialog ] );
            }
        }
    }
}
