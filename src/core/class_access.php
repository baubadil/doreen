<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  Global variables
 *
 ********************************************************************/

/**
 * \page access_control Access control in Doreen
 *
 *  Doreen implements access control with the help of two classes, DrnACL and Access,
 *  instances of which are built according to data in the \ref acls and \ref acl_entries
 *  tables. All access control is built on access control lists, which are
 *  instances of the DrnACL class, and is based on group memberships. Every ticket
 *  (and every ticket template) has an ACL ID in it.
 *
 *  Even though you rarely deal with ACLs in your code directly, a basic understanding
 *  of how Doreen implements them is helpful.
 *
 *  In computer science, when talking about access control, one often uses the linguistic
 *  concepts of "subject", "verb", and "object". With Doreen ACLs, this translates into
 *  the following:
 *
 *   1) An "object", on which actions can be performed; this can be a ticket
 *      (since every ticket has an ACL as a member in the \ref tickets table)
 *      or, in the case of hard-coded system ACLs , a concept like "all users accounts".
 *      The object is not part of the ACL data itself. Instead, the context of
 *      code which verifies the access determines the object; for example, if
 *      a user wants to edit a ticket, the code looks at the ACL that is stored
 *      with the ticket's data (in the \ref tickets table).
 *
 *   2) A list of "subjects", which in Doreen are always group IDs (instances of
 *      the Group class). Again, all access control in Doreen is based on groups.
 *
 *   3) For each such subject, a list of "verbs".
 *
 *  Four verbs are defined according to the classic database CRUD (create, read, update, delete)
 *  model, plus one "mail" action, represented by four flags, which can be ORed together:
 *
 *   -- ACCESS_CREATE: If set, members of the group may create new instances of
 *      something. In the case of tickets, this bit is only relevant for ticket
 *      templates; in that case, users of the group can create new tickets from
 *      that template (which then inherit the ACL from the template). All ticket
 *      templates for which the currently logged in user has "Create" permission
 *      automatically appear in the user's "New" menu. (Consequently, there is
 *      no "New" menu if nobody is logged in; one cannot create tickets without
 *      a user account.)
 *      "Create" actions typically corresponds to a POST API request; see, for
 *      example, the POST /ticket REST API.
 *
 *   -- ACCESS_READ: users may see the object (e.g. ticket) and all of its fields.
 *      This typically corresponds to a GET API request.
 *
 *   -- ACCESS_UPDATE: users may modify an existing object (e.g. ticket).
 *      This typically corresponds to a PUT API request; see, for
 *      example, the PUT /ticket REST API.
 *
 *   -- ACCESS_DELETE: users may delete the object (e.g. ticket). With tickets,
 *      this should be reserved to administrators, since actually deleting a ticket
 *      (including its entire changelog) is normally not something most users should
 *      be allowed to do, unless you want to allow people to rewrite the history
 *      of that Doreen installation.
 *      This typically corresponds to a DELETE API request; see, for
 *      example, the DELETE /ticket REST API.
 *
 *  To determine the access rights for a given user with respect to a particular ticket,
 *  we iterate over the group / permission bits pairs in the ticket's ACL and OR together
 *  the permission bits for every group that the user is a member of. You can use
 *  \ref DrnACL::AssertCurrentUserAccess() for that.
 *
 *  The following entry points are common:
 *
 *   -- For the "subject" perspective (find out what tickets a given user can see or modify),
 *      call \ref Access::GetForUser() to create an Access instance for that user. Then
 *      perform a search, either by constructing your own SearchFilter or by using Access
 *      methods; see \ref Ticket::FindMany() for more.
 *
 *   -- For the "object" perspective, call \ref Ticket::getUserAccess() to find out
 *      the ACCESS_* bits for a given ticket and user.
 *
 *  As an example, let's assume that there are two ticket templates for the default "Wiki page" ticket
 *  type. (Recall that conceptually, a ticket template is a ticket type with an associated
 *  ACL; see \ref intro_ticket_types for the introduction. See the Group class for the
 *  default group names used here.)
 *
 *  <ul><li>ACL ID 1 (from the first template) has the following entries:
 *
 *      -- Group "All users"     => ACCESS_READ
 *
 *      -- Group "Editors"       => ACCESS_READ | ACCESS_CREATE | ACCESS_UPDATE</li>
 *
 *  <li>ACL ID 2 (from the second template) has the following entries:
 *
 *      -- Group "Administrators" => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE</li></ul>
 *
 *  Let's try three different user accounts.
 *
 *  <ol><li>If you request an Access object for a user who is a member of both "All users"
 *  and "Editors", but not of "Administrators", you will get the following ONE item in
 *  $this->aAIDs:
 *
 *   -- ACLID 1 => ACCESS_READ | ACCESS_CREATE | ACCESS_UPDATE
 *
 *  but not ACLID 2 because the user is not a member of "Administrators".</li>
 *
 *  <li>If you request an Access object for a user who is a member of "All users"
 *  and "Gurus", but not of "Editors", you will get the following TWO items in
 *  $this->aAIDs:
 *
 *   --  ACLID 1 => ACCESS_READ   (but not CREATE or UPDATE because the user is not a "Guru")
 *
 *   --  ACLID2 => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE
 * </li>
 *
 *  <li>Finally, let's assume that a user is not logged in. Such a user is represented by
 *  the "Guest" user ID, and he or she will automatically be a member of the system "Guests"
 *  group (and only that group). Since the "Guest" group has not been given any access
 *  in the ACL, the user cannot even see the ticket: it is invisible until he or she logs in.</li></ol>
 */

/********************************************************************
 *
 *  Access class
 *
 ********************************************************************/

/**
 *  An Access object represents the parsed and processed access permissions of a
 *  user account; it is read-only and computed from ACL and group membership
 *  data.
 *
 *  Use \ref GetForUser() to instantiate an Access object for a given User object.
 *
 *  Once awakened, there is only one such Access object per user ID.
 *
 *  You cannot modify access permissions by modifying an Access object; instead,
 *  modify the ACL from which it was generated. Also, when that data is modified,
 *  Access objects still in memory will represent outdated information and would
 *  have to be manually discarded, but since they only live while a page request
 *  is being processed that is not typically worrysome.
 *
 *  Each access object contains an array of ACLs which apply to the given user,
 *  with the ACCESS_CREATE, ACCESS_READ, ACCESS_UPDATE, ACCESS_DELETE and
 *  ACCESS_MAIL flags ORed together.
 *
 *  See \ref access_control for an introduction how Doreen implements access control.
 */
class Access
{
    public $uid;            # User ID. There must be a corresponding User object EXCEPT in the GUEST case, when $uid is 0.
    public $aAIDs;          # Array of ACL IDs with 'aid' => ORed flags

    # Class-global array of objects that have been instantiated already, ordered by UID.
    public static $aAllLoaded = [];


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    protected function __construct()
    {
    }

    /**
     *  Returns the Access instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     *
     *  @return self
     */
    public static function MakeAwakeOnce($uid,
                                         $aAIDs)
        : Access
    {
        if (isset(self::$aAllLoaded[$uid]))
            return self::$aAllLoaded[$uid];

        $o = new self();
        initObject($o,
                   [ 'uid', 'aAIDs' ],
                   func_get_args());
        self::$aAllLoaded[$uid] = $o;
        return $o;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns a flat list of ACL IDs from the member list that contain the given
     *  access flags.
     *
     *  This can be used to produce your own custom queries if \ref findTickets() is
     *  not good enough because you need to pull in other tables. Example:
     *
     *  This will return NULL if there are no ACLs that give this user the requested
     *  access (implying that there are no tickets in the database to be seen).
     *
     *  ```php
     *      # Produce Access object for the current user.
     *      $oAccess = Access::GetForUser(Globals::$oUserCurrent);
     *
     *      # Give me those ACLs in which the user has WRITE access.
     *      $llAIDs = $oAccess->getACLsForAccess(ACCESS_WRITE);
     *
     *      $aids = Database::Get()->makeInIntList($aAIDs);
     *      $res = Database::Get()->exec("SELECT ... FROM tickets WHERE tickets.aid IN ($aids);");
     *  ```
     *
     *  @return array|null
     */
    public function getACLsForAccess($flAccess = ACCESS_READ)
    {
        Debug::FuncEnter(Debug::FL_TICKETACLS, __METHOD__.'()');

        $llAIDs = [];
        if ($this->aAIDs)
            foreach ($this->aAIDs as $aid => $permissions)
            {
                Debug::Log(Debug::FL_TICKETACLS, "ACL $aid: flags ".sprintf("0x%lX", $permissions)." for user $this->uid");
                if ($permissions & $flAccess)
                    $llAIDs[] = $aid;
            }

        $rc = count($llAIDs) ? $llAIDs : NULL;

        Debug::FuncLeave();

        return $rc;
    }

    /**
     *  Private helper that adds $oFilter2 to the given list of search filters.
     */
    private function prepareSearchFilters($aFilters,
                                          SearchFilter $oFilter2)
    {
        /** @var $aFilters SearchFilter[] */
        if ($aFilters)
        {
            if (!is_array($aFilters))
                throw new DrnException("Internal error: aFilters is not an array");
        }
        else
            $aFilters = [];

        $aFilters[] = $oFilter2;

        foreach ($aFilters as $oFilter)
            Debug::Log(Debug::FL_TICKETFIND, "Search filter ".$oFilter->describe());

        return $aFilters;
    }

    /**
     *  Returns a FindResults instance (as returned by Ticket::FindMany) of tickets that
     *
     *   1) match the given search filters, if any, AND
     *
     *   2) fulfill the access permissions by checking them against the Acess members.
     *
     *  This calls \ref Ticket::FindMany() in turn and returns its FindResults instance or NULL if nothing was found.
     *
     * @return FindResults
     */
    public function findTickets($aFilters = NULL,           //!< in: array of SearchFilter instances or NULL
                                $flAccess = ACCESS_READ,
                                $sortby = NULL,             //!< in: "order by" SQL or NULL
                                $page = NULL,               //!< in: page window to display or entire result set if NULL
                                $aActiveFilters = NULL)     //!< in: NULL or empty array or array of field_id => value pairs (see Ticket::FindMany)
    {
        $fr = NULL;

        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__.'()');

        if ($llAIDs = $this->getACLsForAccess($flAccess))
        {
            # Add an ACL ID search filter to the filters passed in by the caller.
            $aFilters = $this->prepareSearchFilters($aFilters,
                                                    SearchFilter::FromACLIDs($llAIDs));

            $fr = Ticket::FindMany($aFilters,
                                   $sortby,
                                   $page,
                                   Globals::$cPerPage,
                                   $aActiveFilters);
        }

        Debug::FuncLeave();

        return $fr;
    }

    /**
     *  Convenience wrapper around Access::findTickets() which returns
     *  template tickets for which this access object's user has CREATE access.
     *
     *  This alls \ref findTickets(), which in turn calls \ref Ticket::FindMany(), so this
     *  returns a FindResults instance or NULL if nothing was found.
     *
     * @return FindResults
     */
    public function findTemplates($aFilters = NULL)     //!< in: additional SearchFilter instances or NULL
    {
        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__.'()');

        $aFilters = $this->prepareSearchFilters($aFilters,
                                                SearchFilter::Templates());

        $fr = $this->findTickets($aFilters,
                                 ACCESS_CREATE,
                                 'template');      # order by template name

        Debug::FuncLeave();

        return $fr;
    }

    /**
     *  Convenience wrapper around \ref  findTickets() which performs a fulltext query using
     *  whichever search backend has been configured, and filtering that by what the user is
     *  allowed to see with READ access. This function is the implementation for full-text search
     *  via the Search GUI entry field.
     *
     *  If $sortby is specified, it is passed on to \ref findTickets(), which in turn passes
     *  it to \ref Ticket::FindMany(). Searching by scores is also handled there.
     *
     *  Since templates are never pushed into the search backend for indexing, they are never
     *  returned here.
     *
     *  This calls \ref findTickets(), which in turn calls \ref Ticket::FindMany(), so this returns a
     *  FindResults instance or NULL if nothing was found.
     *
     * @return FindResults
     */
    public function findByFulltextQuery($query,
                                        $sortby = '!score',         //!< in: orderby field
                                        $page = NULL,               //!< in: page window to display or entire result set if NULL
                                        $aActiveFilters = NULL)     //!< in: NULL or empty array or array of field_id => value pairs (see Ticket::FindMany)
    {
        $fr = NULL;

        Debug::FuncEnter(Debug::FL_TICKETFIND, __METHOD__."(\"$query\")");

        # #12345 means ticket ID, always, regardless of search plugin.
        if (preg_match('/^#(\d+)$/', $query, $aMatches))
        {
            $idTicket = $aMatches[1];
            if (!($fr = $this->findTickets( [ SearchFilter::FromTicketIDs( [ $idTicket ] ) ],
                                           ACCESS_READ)))
                throw new BadTicketIDException($idTicket);
        }
        else
        {
            if (strlen($query) < 3)
                throw new DrnException(L("{{L//Search queries must be at least three characters long}}"));

            $fr = $this->findTickets( [ SearchFilter::Fulltext($query) ],
                                     ACCESS_READ,
                                     $sortby,
                                     $page,
                                     $aActiveFilters);
        }

        Debug::FuncLeave();

        return $fr;
    }

    /**
     *  Returns a flat PHP array (list) of autocomplete suggestion for the given query.
     *  Used by the full-text search box in the GUI via the GET /suggest-searches REST API.
     */
    public function suggestSearches($query)
    {
        $aSuggestions = NULL;

        if ($oSearch = Plugins::GetSearchInstance())
            $aSuggestions = $oSearch->suggestSearches($query,
                                                      $this->getACLsForAccess());

        return $aSuggestions;
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Returns an Access object representing the given user's permissions,
     *  enumerating all the ACL IDs that apply to that user.
     *
     *  @return self
     */
    public static function GetForUser($oUser)
        : Access
    {
        if (!$oUser)
            throw new DrnException("Internal error: oUser cannot be NULL");

        /** @var User $oUser */
        $uid = $oUser->uid;
        if (isset(Access::$aAllLoaded[$uid]))
            return Access::$aAllLoaded[$uid];

        $aAIDs = DrnACL::Load($oUser);
//         Debug::Log("DrnACL::Load(uid=$uid): ".print_r($aAIDs, TRUE));

        return self::MakeAwakeOnce($uid, $aAIDs);
    }
}


/********************************************************************
 *
 *  ACL class
 *
 ********************************************************************/

/**
 *  The DrnACL class represents an Access Control List. In addition to the ID and the name from
 *  the \ref acls table, each ACL has a list of key => value pairs from the \ref acl_entries
 *  table, each holding a group ID and the permissions that are granted to its members.
 *
 *  To determine the access permissions for a given user, iterate over the list and OR together
 *  the permission bits for every group that the user is a member of. See \ref access_control
 *  for details.
 *
 *  Every ticket in Doreen has an ACL ID in it, which gets inherited from the ticket
 *  template it was created from.
 */
class DrnACL
{
    public $aid;
    public $name;
    public $aPermissions;           # list of entries from acl_entries; key = gid, value = ORed permission flags

    # Class-global array of objects that have been instantiated already, ordered by AID.
    public static $aAllLoaded = [];

    private static $fLoadedAll = FALSE;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    protected function __construct()
    {
    }

    /**
     *  Returns the ACL instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    public static function MakeAwakeOnce($aid,
                                         $name,
                                         $aPermissions)
    {
        if (isset(self::$aAllLoaded[$aid]))
            return self::$aAllLoaded[$aid];

        if (!is_array($aPermissions))
            throw new DrnException("not an array");

        $o = new self();
        initObject($o,
                   [ 'aid', 'name', 'aPermissions' ],
                   func_get_args());
        self::$aAllLoaded[$aid] = $o;
        return $o;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns the ACCESS_* bits of the given user according to the ACL
     *  by going through the ACL's group => permission pairs and ORing
     *  together the bits for the groups of which the given user is a
     *  member. See \ref access_control for an introduction.
     *
     *  This is used by \ref Ticket::getUserAccess().
     */
    public function getUserAccess(User $oUser = NULL)
        : int
    {
        # The ACL has a list of gid -> permissions pairs. Go through the groups,
        # match them up with the user's groups, and thus collect the combined
        # permissions flags for this user and this ACL.
        $flCombined = 0;
        if ($oUser)
            foreach ($this->aPermissions as $gid => $flThis)
                if ($oUser->isMember($gid))
                    $flCombined |= $flThis;

        return $flCombined;
    }

    /**
     *  Updates this ACL with the given access control data. $aAccess must be
     *  an array of $gid -> $fl entries, with each $fl being a combination of
     *  ACCESS_* flags. The array replaces all the information in the ACL; the
     *  old ACL entries are not retained.
     */
    public function update($name,           //!< in: descriptive named stored in the acls table or NULL for auto
                           $aPermissions)   //!< in: permission bits array ($gid => ACCESS_* flags)
    {
        if ($name === NULL)
            $name = self::MakeDescriptiveName($aPermissions);

        self::ValidateParameters($name, $aPermissions);

        Database::GetDefault()->beginTransaction();

        # 1) Update the name.
        Database::DefaultExec(<<<EOD
UPDATE acls
SET name = $1
WHERE aid = $2
EOD
                          , array($name, $this->aid));

        # 2) Get the primary indices of all the acl_entries for this ACL;
        # we'll delete those rows after inserting the new ones.
        $aToDelete = [];
        $res = Database::DefaultExec('SELECT i FROM acl_entries WHERE aid = $1',
                                                                          [ $this->aid ] );
        while ($dbrow = Database::GetDefault()->fetchNextRow($res))
            $aToDelete[] = $dbrow['i'];

        # 3) Insert the new entries.
        self::InsertEntries($this->aid, $aPermissions);

        # 4) Now delete the old entries.
        $indices = implode(', ', $aToDelete);
        Database::DefaultExec("DELETE FROM acl_entries WHERE i IN ($indices)");

        # Go!
        Database::GetDefault()->commit();
    }

    /**
     *  Describes the ACL in an list (flat list) of human-readable strings, each saying something like
     *  "Members of 'All users' can read".
     */
    public function describe($fHTML = TRUE)
    {
        $aReturn = [];
        foreach ( $this->aPermissions as $gid => $fl )
        {
            $strGroup = Group::GetName($gid);
            $aReturn[] = L("{{L//Members of %GROUP% can %VERBS%}}",
                           [ '%GROUP%' => ($fHTML) ? Format::HtmlQuotes($strGroup) : "\"$strGroup\"",
                             '%VERBS%' => self::OneFlagsToVerbs($fl)
                           ]
                          );
        }

        return count($aReturn) ? $aReturn : NULL;
    }

    /**
     *  Returns a list of user IDs who can access this ticket in the given way.
     *
     *  The users are returned in $uid => $fl format, without the objects
     *  for speed. (If the User objects are required, the caller can easily
     *  call User::GetAll() and retrieve them.)
     */
    public function getUsersWithAccess($flRequired = ACCESS_READ)
    {
        $aUsers = [];
        $aGroups = Group::GetAll();
        foreach ($this->aPermissions as $gid => $flPermissions)
        {
            # Only loop through the group members for this ACL entry if this entry's
            # permissions flag include the one the caller is looking for.
            If (($flPermissions & $flRequired) == $flRequired)
            {
                if (!($oGroup = $aGroups[$gid]))
                    throw new DrnException("Invalid group ID $gid in ACL {$this->aid}");

                foreach ($oGroup->aMemberIDs as $uid => $dummy)
                    if (isset($aUsers[$uid]))
                        $aUsers[$uid] |= $flPermissions;
                    else
                        $aUsers[$uid] = $flPermissions;
            }
        }

        return $aUsers;
    }


    /********************************************************************
     *
     *  Static public functions
     *
     ********************************************************************/

    /**
     *  Returns the ACL object for the given AID, or NULL if no such ACL exists.
     *
     *  If an ACL object has already been instantiated for this AID, it is returned.
     *  Otherwise we attempt to retrieve it from the database.
     *
     *  May throw for unexpected failures, but not if the ACL doesn't exist.
     *
     * @return DrnACL | null
     */
    public static function Find($aid)
    {
        self::GetAll();

        if (isset(self::$aAllLoaded[$aid]))
            return self::$aAllLoaded[$aid];

        return NULL;
    }

    /**
     *  Returns an array of all the ACLs. Loads them from the database on the first call.
     */
    public static function GetAll()
    {
        if (!self::$fLoadedAll)
        {
            self::Load();
            self::$fLoadedAll = true;
        }

        # Now make a copy but exclude the hard-coded system ACLs that may exist.
        $aACLs = [];
        foreach (self::$aAllLoaded as $aid => $oACL)
            if ($aid > 0)
                $aACLs[$aid] = $oACL;

        return $aACLs;
    }

    /**
     *  Creates a system ACL, which can then be fed into AssertCurrentUserAccess() in
     *  a typical API handler.
     *
     *  As with all ACLs, there should be an "object" (which an operation is to be performed on)
     *  and a "verb", which is one of create, read, update or delete, represented by a combination
     *  of ACCESS_* flags.
     *
     *  This is really just a thin wrapper around MakeAwakeOnce() with an empty ACL name, but it's
     *  easier to grep for.
     *
     *  For example, if you want to restrict user management so that gurus can create, read and update
     *  user accounts, but only admins can delete them, you would do this:
     *
     *      define(MY_SYS_ACL_USER_AID, -999);      # some negative number
     *      DrnACL::CreateSysACL(MY_SYS_ACL_USER_AID, [ Group::ADMINS    => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE,
     *                                                  Group::GURUS     => ACCESS_READ ] );
     *      # Check that the current user has at least "update" access.
     *      DrnACL::AssertCurrentUserAccess(MY_SYS_ACL_USER_AID, ACCESS_UPDATE);
     */
    public static function CreateSysACL($aid,                   //!< in: constant system ACL ID (should be negative)
                                        $aPermissions)          //!< in: permissions array, passed to MakeAwakeOnce()
    {
        self::MakeAwakeOnce($aid,
                            NULL,               # no name
                            $aPermissions);
    }

    /**
     *  Helper that creates ACL_SYS_IMPORT, which some plugins use.
     */
    public static function CreateImportACL()
    {
        self::CreateSysACL(ACL_SYS_IMPORT,
                           [   Group::ADMINS    => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE
                           ] );
    }

    /**
     *  Convenience function for checking that the current user has enough permissions
     *  to perform a particular action.
     *
     *  $aid should be one of the hard-coded ACL_* constants, which are instantiated
     *  in the Doreen code. It is thus not necessary to load ACLs from disk first.
     *
     *  If the current user does not have enough permissions, this throws NotAuthorizedException.
     */
    public static function AssertCurrentUserAccess($aid,
                                                   $flRequired)
    {
        if (!isset(self::$aAllLoaded[$aid]))
            throw new DrnException("Invalid ACL ID $aid");

        $oACL = self::$aAllLoaded[$aid];
        if (($oACL->getUserAccess(LoginSession::$ouserCurrent) & $flRequired) != $flRequired)
            throw new NotAuthorizedException();
    }

    /**
     *  Converts an array of $gid => 'CRUD' entries into an array of
     *  $gid => ACCESS_* flags.
     */
    public static function LettersToFlags($aAccess)
    {
        # Translate the RWCD letters into ACCESS_* bits.
        $aAccess2 = [];
        $aLetters = [ 'C' => ACCESS_CREATE,
                      'R' => ACCESS_READ,
                      'U' => ACCESS_UPDATE,
                      'D' => ACCESS_DELETE,
                      'M' => ACCESS_MAIL ];
        foreach ($aAccess as $gid => $letters)
        {
            $fl = 0;
            foreach (str_split($letters) as $c)
                if (isset($aLetters[$c]))
                    $fl |= $aLetters[$c];
            $aAccess2[$gid] = $fl;
        }

        return $aAccess2;
    }

    /**
     *  Converts the given ACCESS_* flags into a 'CRUD' string.
     */
    public static function OneFlagsToLetters($flPermissions)
    {
        $str = '';
        foreach ( [ ACCESS_CREATE => 'C',
                    ACCESS_READ   => 'R',
                    ACCESS_UPDATE => 'U',
                    ACCESS_DELETE => 'D',
                    ACCESS_MAIL   => 'M' ] as $fl => $letter)
            if ($flPermissions & $fl)
                $str .= $letter;

        return $str;
    }

    /**
     *  Like OneFlagsToLetters(), but converts the given ACCESS_* flags into an
     *  enumeration of verbs instead.
     */
    public static function OneFlagsToVerbs($flPermissions)
    {
        $aVerbs = [];
        foreach ( [ ACCESS_CREATE => '{{L//create}}',
                    ACCESS_READ   => '{{L//read}}',
                    ACCESS_UPDATE => '{{L//update}}',
                    ACCESS_DELETE => '{{L//delete}}',
                    ACCESS_MAIL => '{{L//receive ticket mail}}', ]
                  as $fl => $verb)
            if ($flPermissions & $fl)
                 $aVerbs[] = L($verb);

        return count($aVerbs) ? implode(', ', $aVerbs) : '';
    }

    /**
     *  Converts an array of $gid => ACCESS_* flags into an array of
     *  $gid => 'RWCD' letters.
     */
    public static function FlagsToLetters($aPermissions)        # in: permission bits array ($gid => ACCESS_* flags)
    {
        $aPermissions2 = [];
        foreach ($aPermissions as $gid => $flPermissions)
            $aPermissions2[$gid] = self::OneFlagsToLetters($flPermissions);

        return $aPermissions2;
    }

    /**
     *  Helper to load ACL entries from the database.
     *
     *  If $oUser is NULL, this loads all entries. Used by DrnACL::GetAll().
     *
     *  If not, this loads those entries which have groups of which the given user is a member.
     *  Used by Access::GetForUser().
     */
    public static function Load(User $oUser = NULL)
    {
        $memberships = '';
        # If a user is given, use the groups list from the User object instead of pulling in
        # the memberships table, because memberships from hardcoded accounts like GUEST are
        # not in the database.
        if ($oUser)
        {
            if ($oUser->groups)
                $memberships = ' AND acl_entries.gid IN ('.$oUser->groups.')';
            else
                $memberships = ' AND FALSE';    # Nothing visible.
        }
            # $memberships = "JOIN memberships ON memberships.uid = $uid AND acl_entries.gid = memberships.gid";

        # To avoid a second database round-trip, we simply retrieve the ACL name with
        # every row of the ACL entries.
        $res = Database::DefaultExec(<<<EOD
-- DrnACL::Load()
SELECT
    acls.aid,
    acls.name,
    acl_entries.gid,
    acl_entries.permissions
FROM acls
JOIN acl_entries ON acl_entries.aid = acls.aid$memberships
EOD
                                 );
        $aAIDs = [];
        while ($row = Database::GetDefault()->fetchNextRow($res))
        {
            $aid = $row['aid'];
            $name = $row['name'];
            $gid = $row['gid'];
            $permissions = intval($row['permissions']);         # MUST be intval or PHP may take it as a string and then the binary OR below will produce garbage

//             Debug::Log("loaded acl entry aid=$aid, gid=$gid, perms=$permissions");

            if (isset(self::$aAllLoaded[$aid]))
            {
                $oACL = self::$aAllLoaded[$aid];
                if (isset($oACL->aPermissions[$gid]))
                    $oACL->aPermissions[$gid] |= $permissions;
                else
                    $oACL->aPermissions[$gid] = $permissions;
            }
            else
                self::MakeAwakeOnce($aid, $name, [ $gid => $permissions ]);

            if (!isset($aAIDs[$aid]))
                $aAIDs[$aid] = $permissions;
            else
                $aAIDs[$aid] |= $permissions;
        }

        return $aAIDs;      # for use by Acess::GetForUser
    }

    /**
     *  Creates a new ACL in the database and creates a new ACL instance
     *  in memory accordingly, which is returned.
     *
     *  See update() for the parameters.
     */
    public static function Create($name,            //!< in: descriptive named stored in the acls table or NULL for auto
                                  $aPermissions)    //!< in: permission bits array ($gid => ACCESS_* flags)
    {
        if ($name === NULL)
            $name = self::MakeDescriptiveName($aPermissions);

        self::ValidateParameters($name, $aPermissions);

        Database::GetDefault()->beginTransaction();

        # 1) Create a new ACL entry.
        Database::DefaultExec(<<<EOD
INSERT INTO acls (name) VALUES ( $1    )
EOD
                             , [ $name ] );

        # 2) Get the new ACL's ID.
        $aid = Database::GetDefault()->getLastInsertID('acls', 'aid');

        # 3) Insert the new entries.
        self::InsertEntries($aid, $aPermissions);

        # Go!
        Database::GetDefault()->commit();

        return self::MakeAwakeOnce($aid,
                                   $name,
                                   $aPermissions);
    }


    /********************************************************************
     *
     *  Private helpers
     *
     ********************************************************************/

    public static function ValidateParameters($name,
                                              $aPermissions)
    {
        # Validate parameters.
        if (!$name)
            throw new DrnException("ACL names cannot be empty");
        foreach ($aPermissions as $gid => $fl)
        {
            Group::GetAll();
            if (!isset(Group::$aAwakenedGroups[$gid]))
                throw new DrnException("Invalid group ID $gid in access control list");
            if (($fl & (ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL)) != $fl)
                throw new DrnException("Invalid permission bits $fl with group $gid in access control list");
        }
    }

    public static function MakeDescriptiveName($aPermissions)
    {
        $aclname = '';
        foreach ($aPermissions as $gid => $flPermissions)
        {
            if ($aclname)
                $aclname .= '; ';
            $aclname .= Group::GetName($gid);
            $aclname .= ': ';
            $aclname .= self::OneFlagsToLetters($flPermissions);
        }

        return $aclname;
    }

    /**
     *  Inserts rows into acl_entries. Gets called from both update() and Create().
     */
    private static function InsertEntries($aid,
                                          $aPermissions)
    {
        foreach ($aPermissions as $gid => $fl)
        {
            Database::DefaultExec(<<<EOD
INSERT INTO acl_entries
    ( aid,   gid,   permissions) VALUES
    ( $1,    $2,    $3)
EOD
  , [ $aid,  $gid,  $fl ] );
        }
    }

}
