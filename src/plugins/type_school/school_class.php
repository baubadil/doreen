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
 *  A SchoolClass is a specialized Category that represents a school class of
 *  students (and their parents).
 *
 *  Every school class has four important items associated:
 *
 *   -- two Doreen Group IDs (for the parents, and the "gurus", which are the
 *      representatives that can create student and newsletter tickets);
 *
 *   -- two templates for students and newsletters, which have access permissions
 *      set so that parents can read them all for this class, but only the gurus
 *      can create or modify them.
 *
 *  We extend the Category class so we don't need an extra table. Since we don't
 *  need to look up school classes by parents or guru GID or templates, we
 *  can just store those four IDs in the categories extradata as JSON.
 */
class SchoolClass extends Category
{
    const EXTRAKEY_GID_PARENTS = 'gid_parents';
    const EXTRAKEY_GID_GURUS = 'gid_gurus';
    const EXTRAKEY_ID_STUDENT_TEMPLATE = 'id_student_template';
    const EXTRAKEY_ID_NEWSLETTER_TEMPLATE = 'id_newsletter_template';


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    /**
     *  Creates a new row in the \ref categories table, representing a school class
     *  with the given name. This automatically creates two groups and two ticket templates
     *  and stores their IDs with the category extra data.
     *
     *  Does not create any user accounts, see \ref CreateWithRep().
     */
    public static function Create(User $ouserChanging = NULL,
                                  SchoolClass $oParentSchool = NULL,
                                  $name)
        : self
    {
        Database::GetDefault()->beginTransaction();

        /* We must create the "empty" category first because we need its ID in the results we create below.
           We then update the category extra data with the template and group IDs. */

        /** @var self $oClassCategory */
        $oClassCategory = parent::CreateBase(TicketField::FindOrThrow(FIELD_SCHOOL_CATEGORY_CLASS),
                                             $name,
                                             NULL);

        // Create two groups for this class first so we can use the IDs in the results.
        $oGroupGurus = Group::Create("$name gurus");
        $oGroupParents = Group::Create("$name parents");

        $otypeStudents = TicketType::FindFromGlobalConfig(PluginSchool::SCHOOL_STUDENT_TYPE_ID_CONFIGKEY, TRUE);
        $oStudentTemplate = Ticket::CreateTemplate($ouserChanging,
                                                   "Student in $name",
                                                   $otypeStudents,
                                                   $oClassCategory->id,
                                                   [
                                                       Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL,
                                                       $oGroupGurus->gid => ACCESS_CREATE | ACCESS_UPDATE | ACCESS_READ | ACCESS_MAIL,
                                                       $oGroupParents->gid => ACCESS_READ,
                                                   ]);

        /* Note, by default no ACCESS_MAIL for group gurus or parents. The newsletter is sent out by mail by a special method
         * SchoolStudentTicket::buildAndSendTicketMail outside of the ticket mail system. Admins should still receive regular ticket mail. */
        $otypeNewsletters = TicketType::FindFromGlobalConfig(PluginSchool::SCHOOL_NEWSLETTER_TYPE_ID_CONFIGKEY, TRUE);
        $oNewsletterTemplate = Ticket::CreateTemplate($ouserChanging,
                                                      "Newsletter for $name",
                                                      $otypeNewsletters,
                                                      $oClassCategory->id,
                                                      [
                                                          Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE | ACCESS_MAIL,
                                                          $oGroupGurus->gid => ACCESS_CREATE | ACCESS_UPDATE | ACCESS_READ,
                                                          $oGroupParents->gid => ACCESS_READ,
                                                      ]);

        // Now store the extra data with the four IDs we have created.
        $oClassCategory->setExtra([ self::EXTRAKEY_GID_PARENTS => $oGroupParents->gid,
                                    self::EXTRAKEY_GID_GURUS => $oGroupGurus->gid,
                                    self::EXTRAKEY_ID_STUDENT_TEMPLATE => $oStudentTemplate->id,
                                    self::EXTRAKEY_ID_NEWSLETTER_TEMPLATE => $oNewsletterTemplate->id
                                  ]);

        Database::GetDefault()->commit();

        return $oClassCategory;
    }

    /**
     *  Calls \ref Create() first and then creates a user account for the parent representative
     *  and adds that account to the parents and parents rep group for the class.
     *
     *  If $fSendInvitation is TRUE, this sends out an invitation email to the user so that they
     *  can log in using the password reset mechanism.
     */
    public static function CreateWithRep(User $ouserChanging = NULL,
                                         $classname,
                                         $email,
                                         $repLongname,
                                         $repMobile,
                                         bool $fSendInvitation)
    {
        Database::GetDefault()->beginTransaction();

        $oClass = self::Create($ouserChanging,
                               NULL,
                               $classname);

        User::ValidateEmail($email, 'email', FALSE);
        $oUser = $oClass->createUserForParent($email,
                                              $repLongname,
                                              $repMobile,
                                              $fSendInvitation);
        // Add the user to the reps group in addition to the parents group.
        $oUser->addToGroup($oClass->getParentRepsGroupOrThrow());

        Database::GetDefault()->commit();
    }


    /********************************************************************
     *
     *  Newly introduced instance methods
     *
     ********************************************************************/

    // Max age for confirming Doreen user accounts newly created by this plugin. Make it two days for now.
    const SCHOOL_USER_MAXAGE_MINUTES = 2 * 24 * 60;

    /**
     *  Creates a new Doreen user account for the given email and long name, using
     *  the email address as the login name as well.
     *
     *  Adds the new user to "All users" and to the "parents" group for this
     *  school class.
     *
     *  If ($fSendInvitation == TRUE), this sends out an invitation mail.
     */
    public function createUserForParent(string $email,
                                        string $longname,
                                        string $mobile,
                                        bool $fSendInvitation)
        : User
    {
        $oGroupParents = $this->getParentsGroupOrThrow();

        User::ValidateEmail($email);
        $pass = User::GeneratePassword();
        $oUserThis = User::Create($email,       // use email for login
                                  $pass,
                                  $longname,
                                  $email,
                                  User::FLUSER_TICKETMAIL);
        $oUserThis->setKeyValue(ParentForJson::USER_EXTRA_MOBILE,
                                $mobile,
                                FALSE);

        $oUserThis->addToGroup($oGroupParents);

        $initialLang = DrnLocale::Get();

        if ($fSendInvitation)
        {
            $token = User::RequestResetPassword($email,
                                                self::SCHOOL_USER_MAXAGE_MINUTES,
                                                100);       // This should be sufficient for what the class reps will create.
            $link = WebApp::MakeUrl("/school-welcome/$email/$token");

            # But only send the email to the given address if we have a user with that email.
            if ($oUser = User::FindByEmail($email))
            {
                DrnLocale::Set('de_DE');
                Email::Enqueue([ $email ],  # To
                               NULL,        # BCC
                               L("{{L//Welcome to %DOREEN%}}"),
                               NULL,        # HTML mail
                               L(<<<EOD
{{L/SCHOOLWELCOME/
Welcome to %DOREEN%!

We have created a new user account for you at %SERVER%. This will allow you to view students and newsletters for
your child's school class and also manage your student's data online.

Please click on the following link, which brings you to a page allowing you to set a password for your user account:

%LINK%

This link is valid for two days. After that, you can request a password reset yourself at %SERVER%.}}
EOD
                                   , [ '%SERVER%' => WebApp::MakeUrl(''),
                                       '%LINK%' => $link
                                     ] )
                );
            }
        }

        DrnLocale::Set($initialLang, FALSE);

        return $oUserThis;
    }

    /**
     *  Returns the ticket template that can create a new student of this class.
     */
    public function getStudentTemplateOrThrow()
        : SchoolStudentTicket
    {
        $this->decodeExtra();
        if (!($id = $this->aExtraDecoded[self::EXTRAKEY_ID_STUDENT_TEMPLATE] ?? NULL))
            throw new DrnException("cannot find student template ID for school class $this->id ($this->name)");

        /** @var SchoolStudentTicket $oTemplate */
        $oTemplate = NULL;
        if (!$oTemplate = Ticket::FindOne($id))
            throw new DrnException("cannot find student template for school class $this->id ($this->name)");

        return $oTemplate;
    }

    /**
     *  Returns the ticket template that can create a new newsletter for this class.
     */
    public function getNewsletterTemplateOrThrow()
        : Ticket # TODO
    {
        $this->decodeExtra();
        if (!($id = $this->aExtraDecoded[self::EXTRAKEY_ID_NEWSLETTER_TEMPLATE] ?? NULL))
            throw new DrnException("cannot find newsletter template ID for school class $this->id ($this->name)");

        /** @var Ticket $oTemplate */ # TODO
        $oTemplate = NULL;
        if (!$oTemplate = Ticket::FindOne($id))
            throw new DrnException("cannot find newsletter template for school class $this->id ($this->name)");

        return $oTemplate;
    }

    /**
     *  Returns the Doreen group for the parents of this class.
     */
    public function getParentsGroupOrThrow()
        : Group
    {
        $this->decodeExtra();
        if (!($id = $this->aExtraDecoded[self::EXTRAKEY_GID_PARENTS] ?? NULL))
            throw new DrnException("cannot find parents group ID for school class $this->id ($this->name)");

        if (!($oGroup = Group::Find($id)))
            throw new DrnException("cannot find parents group for school class $this->id ($this->name)");

        return $oGroup;
    }

    /**
     *  Returns the Doreen group for the representatives of this class.
     */
    public function getParentRepsGroupOrThrow()
    : Group
    {
        $this->decodeExtra();
        if (!($id = $this->aExtraDecoded[self::EXTRAKEY_GID_GURUS] ?? NULL))
            throw new DrnException("cannot find parent representatives group ID for school class $this->id ($this->name)");

        if (!($oGroup = Group::Find($id)))
            throw new DrnException("cannot find parent representatives group for school class $this->id ($this->name)");

        return $oGroup;
    }

}

