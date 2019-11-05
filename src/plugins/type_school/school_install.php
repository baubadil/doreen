<?php

namespace Doreen;


class SchoolInstall extends InstallBase
{
    public function doInstall()
    {
        if ($this->pluginDBVersionNow < 1)
        {
            $this->fRefreshTypes = TRUE;
        }

        $this->addInstallTicketFields(
            [
                FIELD_SCHOOL_CATEGORY_CLASS => [ 'school_class',   '', 0.8, NULL ],     // Category, used as project_id
                FIELD_STUDENT               => [ 'school_student', 'ticket_texts', 0.9, NULL ],     // before title!
                FIELD_STUDENT_PARENTS       => [ 'school_parents', 'ticket_ints', 3.2, NULL ],
            ] );

        if ($this->fRefreshTypes)
        {
            GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"Student\" ticket type", function()
            {
                $llDetls = [ FIELD_TITLE, FIELD_SCHOOL_CATEGORY_CLASS, FIELD_STUDENT, FIELD_STUDENT_PARENTS ];
                $llList =  [ FIELD_TITLE, FIELD_SCHOOL_CATEGORY_CLASS, FIELD_STUDENT, FIELD_STUDENT_PARENTS ];
                TicketType::Install(PluginSchool::SCHOOL_STUDENT_TYPE_ID_CONFIGKEY,
                                    PluginSchool::SCHOOL_STUDENT_TYPENAME,
                                    NULL,
                                    $llDetls,
                                    $llList,
                                    NULL,        # workflow
                                    0);
            });
            GlobalConfig::AddInstall("$this->htmlPlugin: Create/update \"Newsletter\" ticket type", function()
            {
                $llDetls = [ FIELD_TITLE, FIELD_SCHOOL_CATEGORY_CLASS, FIELD_DESCRIPTION ];
                $llList =  [ FIELD_TITLE, FIELD_SCHOOL_CATEGORY_CLASS, FIELD_DESCRIPTION ];
                TicketType::Install(PluginSchool::SCHOOL_NEWSLETTER_TYPE_ID_CONFIGKEY,
                                    PluginSchool::SCHOOL_NEWSLETTER_TYPENAME,
                                    NULL,
                                    $llDetls,
                                    $llList,
                                    NULL,        # workflow
                                    0);
            });
        }

        if ($this->pluginDBVersionNow < 1)
        {
//            self::addInstallTemplate(PluginNewsletter::STUDENT_TYPE_ID_CONFIGKEY,
//                                     "Student Delfine",
//                                     [ Group::ALLUSERS => ACCESS_READ,
//                                       Group::EDITORS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE,
//                                       Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE | ACCESS_DELETE,
//                                     ],
//                                     PluginNewsletter::STUDENT_TEMPLATE_ID_CONFIGKEY);
        }
    }
}
