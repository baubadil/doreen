<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  ApiTicket class
 *
 ********************************************************************/

/**
 *  Abstract class that combines ticket API handler implementations.
 *
 *  This class only exists to keep related code together and to be able
 *  to load this file using our autoloader.
 */
abstract class ApiTicket
{
    /**
     *  Parses the arguments in WebApp for drill_* parameters and returns an array of
     *  field_id => [ value, ... ] pairs for \ref GetMany() or Ticket::FindMany().
     */
    static function FetchDrillDownParams()
        : array
    {
        $aDrillDownFilters = [];

        /* This will hold all field IDs that support drill-down. We start with FIELD_TYPE and
           add the field names from Globals::$aFieldDrillDowns. */
        $aFilterFieldsByID = [ FIELD_TYPE => 'type' ];
        foreach (TicketField::GetDrillDownIDs() as $field_id)
            $aFilterFieldsByID[$field_id] = TicketField::FindOrThrow($field_id)->name;

        // Now go through the filters that the user currently wants enabled:
        foreach ($aFilterFieldsByID as $field_id => $name)
        {
            $values = WebApp::FetchParam("drill_$name", FALSE);
            if (    ($values !== NULL)
                 && ($aValues = explodeCommaIntList($values))
               )
                $aDrillDownFilters[$field_id] = $aValues;
        }

        return $aDrillDownFilters;
    }

    /**
     *  Parses the arguments in WebApp for multiple_* parameters and returns an array of
     *  [field_id] => name pairs.
     */
    static function FetchDrillMultipleParams()
        : array
    {
        $aDrillMultiple = [];

        /* This will hold all field IDs that support drill-down. We start with FIELD_TYPE and
           add the field names from Globals::$aFieldDrillDowns. */
        $aFilterFieldsByID = [ FIELD_TYPE => 'type' ];
        foreach (TicketField::GetDrillDownIDs() as $field_id)
            $aFilterFieldsByID[$field_id] = TicketField::FindOrThrow($field_id)->name;

        // Now go through the filters that the user currently wants enabled:
        foreach ($aFilterFieldsByID as $field_id => $name)
        {
            if (    ($oFieldHandler = FieldHandler::Find($field_id))
                 && (!$oFieldHandler->fDrillMultiple)
                 && ($oFieldHandler->fDrillMultipleOptional))
            {
                $values = WebApp::FetchParam(FieldHandler::MULTIPLE_PARTICLE_PREFIX.$name, FALSE);
                if ($values !== NULL)
                    $aDrillMultiple[$field_id] = $name;
            }
        }

        return $aDrillMultiple;
    }

    /**
     *  Returns the "X tickets found, took X seconds" string from the given FindResults.
     */
    static function MakeResultsString(FindResults $fr = NULL)
        : string
    {
        $time = Format::TimeTaken(Globals::TimeSoFar());
        if ($fr)
            return Ln('{{Ln//One result, search took %SECONDS% seconds.//%COUNT% results, search took %SECONDS% seconds.}}',
                      $fr->cTotal,
                      [ '%COUNT%' => Format::Number($fr->cTotal),
                        '%SECONDS%' => $time ] );
        return L('{{L//Nothing found, search took %SECONDS% seconds.}}',
                 [ '%SECONDS%' => $time ] );
    }

    /**
     *  Produces a URL particles array from the given parameters.
     */
    static function ComposeParticles(string $fulltext = NULL,        //!< in: fulltext query or NULL
                                     string $strTypes = NULL,        //!< in: comma-separated list of integer type IDs or NULL
                                     array $aDrillDownFilters,       //!< in: empty array or array of field_id => [ value, ... ] pairs; must not be NULL
                                     string $sortby = NULL,          //!< in: sortby criterion for Ticket::FindMany()
                                     string $format = NULL,          //!< in: NULL or 'grid'
                                     array $aDrillMultiple = NULL)   //!< in: NULL or array of drill down fields that can have multiple values selected but are radios by default.
        : array
    {
        $a = [];
        if ($fulltext)
            $a['fulltext'] = $fulltext;
        if ($strTypes)
            $a['types'] = $strTypes;
        if ($aDrillDownFilters)
            foreach ($aDrillDownFilters as $field_id => $values)
                $a['drill_'.TicketField::GetName($field_id)] = implode(',', $values);
        if ($sortby)
            $a['sortby'] = $sortby;
        if ($format)
            $a['format'] = $format;
        if ($aDrillMultiple)
        {
            foreach ($aDrillMultiple as $field_id => $name)
                $a[FieldHandler::MULTIPLE_PARTICLE_PREFIX.$name] = 1;
        }
        return $a;
    }

    /**
     *  Implementation for the GET /tickets REST API. This parses the WebApp arguments.
     *
     * @return void
     */
    static function GetMany($page,
                            string $fulltext = NULL,        //!< in: fulltext query or NULL
                            string $strTypes = NULL,        //!< in: comma-separated list of integer type IDs or NULL
                            array $aDrillDownFilters,       //!< in: empty array or array of field_id => [ value, ... ] pairs; must not be NULL
                            string $sortby = NULL,
                            string $format = NULL)          //!< in: NULL or 'grid'
    {
        $aHardFilters = [];

        if (!$page)
            $page = 1;
        else if (!isInteger($page))
            throw new DrnException("Invalid parameter: page must be numeric");

        if ($fulltext)
        {
            $aHardFilters[] = SearchFilter::Fulltext($fulltext);
        }

        if ($strTypes)
            $aHardFilters[] = SearchFilter::FromTicketTypes(explodeCommaIntList($strTypes));      // Throws on errors

//        if (    (!count($aHardFilters))
//             && (!LoginSession::IsCurrentUserAdmin())
//           )
//            throw new DrnException("At least one search criterion must be specified, refusing to list all tickets");

        # Restrict search to current user in any case.
        $oAccess = Access::GetForUser(LoginSession::$ouserCurrent);
        $aHardFilters[] = SearchFilter::FromACLIDs($oAccess->getACLsForAccess(ACCESS_READ));

        # And never return templates.
        $aHardFilters[] = SearchFilter::NonTemplates();

        # And exclude the default main page template.
        if ($idTitlePageWikiTicket = Blurb::GetTitlePageWikiTicket())
            $aHardFilters[] = SearchFilter::ExcludeTicketIds( [ $idTitlePageWikiTicket ] );

        # Receives list of sort criteria.
        $aSortbys = [];

        if ($format == 'grid')
            $cPerPage = Globals::$cPerPageGrid;
        else
            $cPerPage = Globals::$cPerPage;

        if (!$sortby)
            $sortby = SearchOrder::FromFilters($aHardFilters)->getFormattedParam();

        $cTotal = 0;
        if ($fr = Ticket::FindMany($aHardFilters,
                                   $sortby,
                                   $page,               # page
                                   $cPerPage,
                                   $aDrillDownFilters))    # active filters -- MUST be at least [] with full-text
        {
            Globals::Profile("Returned from FindMany, ".$fr->cTotal." results, populating page $page...");

            $aVisibleFields = TicketType::GetManyVisibleFields($fr->aTypes,
                                                               TicketType::FL_INCLUDE_CORE);      # flags: include neither children nor hidden fields nor details
            Ticket::PreloadDisplay($fr->aTickets,
                                   $aVisibleFields,
                                   Ticket::POPULATE_DETAILS);

            Globals::Profile("Done populating, now building JSON...");
            $cTotal = $fr->cTotal;

            $aOrderBys = SearchOrder::GetAll($fr->aTypes, !!$fulltext);
            $aSortbys = array_map(function(SearchOrder $order) {
                return $order->toJSON();
            }, $aOrderBys);
        }

        $aParticles = self::ComposeParticles($fulltext, $strTypes, $aDrillDownFilters, $sortby, $format, self::FetchDrillMultipleParams());
        $foundString = self::MakeResultsString($fr);
        WebApp::$aResponse += Ticket::MakeApiResult($cTotal,
                                                    $page,
                                                    $cPerPage,
                                                    ($fr) ? $fr->aTickets : NULL,
                                                    Ticket::JSON_LEVEL_MINIMAL,
                                                    $foundString,
                                                    $foundString,
                                                    $format,
                                                    'tickets',
                                                    $aParticles,
                                                    ($fr) ? $fr->llHighlights : []);

        if ($fr)
        {
            WebApp::$aResponse['sortby'] = $sortby;
            WebApp::$aResponse['aSortbys'] = count($aSortbys) ? $aSortbys : NULL;

            if ($format == 'grid')
                if ($aHTMLFilters = $fr->makeFiltersHtml(MODE_READONLY_GRID, 'tickets', $aParticles))
                {
                    $aForJSON = [];
                    foreach ($aHTMLFilters as $field_id => $value)
                    {
                        $aForJSON[] = [ 'id' => $field_id,
                                        'name' => TicketField::GetName($field_id),
                                        'name_formatted' => ucfirst(TicketField::GetDrillDownFilterName($field_id)),
                                        'html' => $value,
                                        'multiple' => FieldHandler::Find($field_id)->shouldShowMultipleToggle() ];
                    }
                    WebApp::$aResponse['filters'] = $aForJSON;
                }
        }
        else if (count($aDrillDownFilters))
        {
            if (    ($format == 'grid')
                 && ($aHTMLFilters = FindResults::BuildFiltersHtml(MODE_READONLY_LIST, 'tickets', $aParticles, NULL, TRUE)))
            {
                $aForJSON = [];
                foreach ($aHTMLFilters as $field_id => $value)
                {
                    $aForJSON[] = [ 'id' => $field_id,
                                    'name' => TicketField::GetName($field_id),
                                    'name_formatted' => ucfirst(TicketField::GetDrillDownFilterName($field_id)),
                                    'html' => $value,
                                    'multiple' => FieldHandler::Find($field_id)->shouldShowMultipleToggle() ];
                }
                WebApp::$aResponse['filters'] = $aForJSON;
            }
        }

        $aFormats = [];
        foreach ( [ [ 'list', L("{{L//Show results as list}}"), 'list'],
                    [ 'grid', L("{{L//Show results as grid}}"), 'table' ],
                  ] as $ll)
            $aFormats[] = [ 'format' => $ll[0],
                            'hover' => $ll[1],
                            'htmlIcon' => Icon::Get($ll[2]) ];

        WebApp::$aResponse['format'] = $format;
        WebApp::$aResponse['aFormats'] = $aFormats;

        Globals::Profile("Done building JSON");
    }

    /**
     *  Implementation for the GET /ticket REST API.
     */
    static function GetOne($idTicket)
    {
        $oTicket = Ticket::FindForUser($idTicket, LoginSession::$ouserCurrent, ACCESS_READ);
        WebApp::$aResponse['results'] = $oTicket->toArray();
    }

    /**
     *  Implementation for the POST /ticket REST API.
     *
     *  This creates a new ticket.
     *
     *  In addition to the usual "status: OK" fields in the JSON reply, we
     *  will add a field "ticket_id: 123" on success so that the caller
     *  learns about the ID of the newly created ticket.
     */
    static function Post()
    {
        $idTemplate = WebApp::FetchParam('template');
        $oTemplate = Ticket::FindTemplateOrThrow($idTemplate);

        if (!($oTemplate->getUserAccess(LoginSession::$ouserCurrent) & ACCESS_CREATE))
            throw new NotAuthorizedCreateException();

        $oTicket = $oTemplate->createAnother(LoginSession::$ouserCurrent,        // create
                                             LoginSession::$ouserCurrent,        // lastmod
                                             WebApp::$aArgValues);
        WebApp::$aResponse['ticket_id'] = $oTicket->id;
    }

    /**
     *  Implementation for the PUT /ticket REST API.
     *
     *  This updates an existing ticket.
     */
    static function Put($ticket_id)
    {
        # Find the ticket. This will throw if the current user may not update.
        $oTicket = Ticket::FindForUser($ticket_id,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_UPDATE);

        # Call Ticket::update(), and hand it the complete HTTP POST/PUT data array,
        # which should have all the required values, or else the method will throw.
        /* $cChanged = */ $oTicket->update(LoginSession::$ouserCurrent,
                                           WebApp::$aArgValues);
    }

    static function PutPriority($ticket_id,
                                $priority)
    {
        # Find the ticket. This will throw if the current user may not update.
        $oTicket = Ticket::FindForUser($ticket_id,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_UPDATE);
        $oTicket->setPriority(LoginSession::$ouserCurrent,
                              $priority);
    }

    /**
     *  Implementation for the DELETE /ticket REST API to delete an existing
     *  ticket.
     */
    static function Delete($ticket_id)
    {
        $oTicket = Ticket::FindForUser($ticket_id,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_READ);
        if (!$oTicket->canDelete(LoginSession::$ouserCurrent))
            throw new NotAuthorizedException();

        Ticket::Delete( [ $ticket_id => $oTicket ] );
    }

    /**
     *  Implementation for the POST /comment REST API.
     */
    static function PostTicketComment()
    {
        $ticket_id = WebApp::FetchParam('ticket_id');
        /** @var Ticket $oTicket */
        $oTicket = Ticket::FindForUser($ticket_id, LoginSession::$ouserCurrent, ACCESS_CREATE);

        // Explicitly check for canComment rights, in case they are not just ACCESS_CREATE
        if (!$oTicket->canComment(LoginSession::$ouserCurrent))
            throw new NotAuthorizedException();

        $comment = WebApp::FetchParam('comment');

        $comment_id = $oTicket->addComment(LoginSession::$ouserCurrent,
                                          $comment);

        WebApp::$aResponse += [ 'comment_id' => $comment_id,
                                'comment' => $comment ];
    }

    /**
     *  Implementation for the PUT /comment/:comment_id REST API.
     */
    static function PutTicketComment()
    {
        $comment_id = (int)WebApp::FetchParam('comment_id');
        $ticket_id = ChangelogRow::FindTicketIDForRow($comment_id);

        /** @var Ticket $oTicket */
        $oTicket = Ticket::FindForUser($ticket_id,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_CREATE);

        if (!$oTicket->canUpdateComment(LoginSession::$ouserCurrent, $comment_id))
            throw new NotAuthorizedException();

        $comment = WebApp::FetchParam('comment');
        $newId = $oTicket->updateComment(LoginSession::$ouserCurrent,
                                         $comment_id,
                                         $comment);

        WebApp::$aResponse += [ 'comment_id' => $newId,
                                'comment' => $comment ];
    }

    /**
     *  Implementation for the DELETE /comment/:comment_id REST API.
     */
    static function DeleteTicketComment()
    {
        $comment_id = (int)WebApp::FetchParam('comment_id');
        $ticket_id = ChangelogRow::FindTicketIDForRow($comment_id);

        if (!$ticket_id)
            throw new DrnException("Could not find ticket ID");

        /** @var Ticket $oTicket */
        $oTicket = Ticket::FindForUser($ticket_id,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_CREATE);

        if (!$oTicket->canUpdateComment(LoginSession::$ouserCurrent, $comment_id))
            throw new NotAuthorizedException();

        $oTicket->deleteComment(LoginSession::$ouserCurrent, $comment_id);
    }

    /**
     *  Implementation for the POST /attachment REST API.
     */
    static function PostAttachment($ticket_id)
    {
        /*  The global $_FILES array gives us something like this:
         *
         * -- if single:
             Array
                                         (
                                             [file] => Array
                                                 (
                                                     [name] => IMG_7791.JPG
                                                     [type] => image/jpeg
                                                     [tmp_name] => /tmp/phpt5th4p
                                                     [error] => 0
                                                     [size] => 5561783
                                                 )
                                         )
         *
         * -- if multiple:
            Array (
                  [file] => Array (
                          [name] => Array (
                                  [0] => 10406394_10100291552558501_1643969523098551237_n.jpg
                                  [1] => 10540637_10152532123571123_3163102020723460790_n.jpg
                              )
                          [type] => Array (
                                  [0] => image/jpeg
                                  [1] => image/jpeg
                              )
                          [tmp_name] => Array (
                                  [0] => /tmp/phpDzOoWf
                                  [1] => /tmp/php9Bjioy
                              )
                          [error] => Array (
                                  [0] => 0
                                  [1] => 0
                              )
                          [size] => Array (
                                  [0] => 123683
                                  [1] => 58214
                              )
                      )
              ) */

        $oTicket = Ticket::FindForUser($ticket_id, LoginSession::$ouserCurrent, ACCESS_CREATE);

        $llBinaries = [];

        if (is_array($_FILES['file']['name']))
        {
            foreach ($_FILES['file']['name'] as $id => $filename)
                if ($_FILES['file']['error'][$id] === 0)
                {
                    $type = $_FILES['file']['type'][$id];
                    $tmp_name = $_FILES['file']['tmp_name'][$id];
                    $size = $_FILES['file']['size'][$id];

                    if (!is_uploaded_file($tmp_name))
                        throw new DrnException("Invalid uploaded file");

                    $llBinaries[] = Binary::CreateUploading($filename, $type, $size, $tmp_name);
                }
        }
        else
            $llBinaries[] = Binary::CreateUploading($_FILES['file']['name'],
                                                    $_FILES['file']['type'],
                                                    $_FILES['file']['size'],
                                                    $_FILES['file']['tmp_name']);

        require_once INCLUDE_PATH_PREFIX.'/core/class_fieldhandlers2.php';
        $nlsJustNow = L('{{L//just now}}');

        $aBinaryIDs = [];
        $c = 0;
        foreach ($llBinaries as $oBinary)
        {
            $oTicket->attachFile(LoginSession::$ouserCurrent,
                                 $oBinary);

            $aBinaryIDs[] = $oBinary->idBinary;
            $htmlChangelogItem = AttachmentHandler::FormatChangelogItemImpl($oBinary);
            $user = LoginSession::$ouserCurrent->login;
            WebApp::$aResponse['aChangelogItems'][$c++] = "<tr class=\"animated zoomInDown\"><td>$nlsJustNow</td><td>$user</td><td>$htmlChangelogItem</td></tr>";
        }

        WebApp::$aResponse['aBinaryIDs'] = $aBinaryIDs;
    }

    /**
     *  Implementation for the GET /ticket-debug-info REST API.
     */
    static function GetDebugInfo($idTicket)
    {
        if (!LoginSession::CanSeeTicketDebugInfo())
            throw new NotAuthorizedException();

        $oTicket = Ticket::FindForUser($idTicket,
                                       LoginSession::$ouserCurrent,
                                       ACCESS_READ);
        WebApp::$aResponse['htmlGroups'] = $oTicket->describeACL(0);
        $aUsers = [];
        $aAllUsers = User::GetAll();
        foreach ($oTicket->getUsersWithAccess() as $uid => $fl)
            $aUsers[$uid] = $aAllUsers[$uid]->longname;

        WebApp::$aResponse['usersRead'] = $aUsers;
        if (count($aUsers))
            WebApp::$aResponse['usersReadFormatted'] = '<ul><li>'.implode("</li>\n<li>", array_values($aUsers))."</li></ul>";
        else
            WebApp::$aResponse['usersReadFormatted'] = L("{{L//Nobody}}");

        WebApp::$aResponse['type'] = (int)$oTicket->oType->id;
        WebApp::$aResponse['typeFormatted'] = $oTicket->oType->getName();

        WebApp::$aResponse['class'] = $oTicket->oType->getTicketClassName();

        $aFields = [];
        $oTicket->populate(TRUE);       // details
        foreach ($oTicket->aFieldData as $field_id => $data)
        {
            $name = '?';
            if ($oField = TicketField::Find($field_id))
                $name = $oField->name;
            $aFields += [ $field_id => [ 'fieldName' => $name,
                                         'data' => $data ] ];
        }
        WebApp::$aResponse['fields'] = $aFields;
    }

    /**
     *  Implementation for the GET /templates REST API. This is used
     *  by the GUI for building the "new" submenu. It hides results
     *  whose type has FL_HIDE_TEMPLATES set.
     */
    static function GetTemplates($idCurrentTicket)
    {
        $aReturn = [];
        $acc = Access::GetForUser(LoginSession::$ouserCurrent);
        if ($findResultTemplates = $acc->findTemplates())
        {
            // Sort with localized strings replaced.
            usort($findResultTemplates->aTickets, function(Ticket $a, Ticket $b) {
                 return strcmp($a->getTemplateName(), $b->getTemplateName());
            });
            foreach ($findResultTemplates->aTickets as $ticket_id => $oTicket)
                if ($oTicket->canCreate(LoginSession::$ouserCurrent))
                    $aReturn[] = $oTicket->getTemplateJson($idCurrentTicket);
        }

        return $aReturn;
    }

    /**
     *  Implementation for the GET /all-templates REST API.
     */
    static function GetAllTemplates()
    {
        if (!LoginSession::IsCurrentUserAdminOrGuru())
            throw new NotAuthorizedException();

        WebApp::$aResponse['results'] = Ticket::FindAllTemplatesForJson();
    }

    static function PutTemplateUnderTicket(int $idTicket,
                                           int $idTemplate)
    {
        if (!LoginSession::IsCurrentUserAdminOrGuru())
            throw new NotAuthorizedException();

        if (!($oTicket = Ticket::FindOne($idTicket)))
            throw new DrnException("Invalid ticket ID #$idTicket");

        $oTemplate = Ticket::FindTemplateOrThrow($idTemplate);

        $oTicket->setTemplateRecursively($oTemplate);
    }

    /**
     *  Checks if the user can create attachments on the ticket. Then gets the
     *  binary and renames it.
     */
    static function RenameAttachment(string $idTicket,
                                     string $idBinary,
                                     string $newName)
    {
        if (    !($oTicket = Ticket::FindForUser($idTicket,
                                                 LoginSession::$ouserCurrent,
                                                 ACCESS_CREATE))
             || !($oBinary = Binary::Load($idBinary)))
            throw new APIException($idBinary, "Could not find this binary", 404);

        if (!$oTicket->canUploadFiles(LoginSession::$ouserCurrent))
            throw new NotAuthorizedException;

        $oBinary->rename($newName, LoginSession::$ouserCurrent);
    }

    /**
     *  Checks if the user can create attachments on the ticket. Then gets the
     *  binary and marks it as hidden.
     */
    static function HideAttachment(string $idTicket,
                                   string $idBinary)
    {
        if (    !($oTicket = Ticket::FindForUser($idTicket,
                                                 LoginSession::$ouserCurrent,
                                                 ACCESS_CREATE))
             || !($oBinary = Binary::Load($idBinary)))
            throw new APIException($idBinary, "Could not find this binary", 404);

        if (!$oTicket->canUploadFiles(LoginSession::$ouserCurrent))
            throw new NotAuthorizedException;

        $oBinary->hide(LoginSession::$ouserCurrent);
    }
}
