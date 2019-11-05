/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import { CreateOrEditTableRowDialog, AjaxTableRenderedBase, ConfirmDeleteTableRowDialog } from 'core/inc-ajaxtable';
import { IUser, IGroup } from 'core/inc-api-types';
import { myEscapeHtml, drnFindByID } from 'core/shared';
import AjaxModal from 'core/inc-ajaxmodal';
import TabbedPage from 'core/inc-tabbedpage';
import { drnGetUrlParameterInt } from 'core/inc-globals';
import _ from 'core/nls';

/********************************************************************
 *
 *  CreateOrEditUserDialog
 *
 ********************************************************************/

class CreateOrEditUserDialog extends CreateOrEditTableRowDialog
{
    // Object abused for remembering user memberships; keys = group IDs.
    private oMemberships = {};

    private jqGroups: JQuery;

    constructor(oParent: UsersTab,
                oUser: IUser | null)        //!< in: user to edit; if null, then this is a "create user" dialog
    {
        super('editUserDialog',
              [ 'password', 'longname', 'email', 'fTicketMail', 'groups' ],
              '/user',
              oParent,
              oUser);

        if (oUser)
        {
            oParent.replaceUserFields(this, oUser);

            this.findDialogItem('login-div').text(myEscapeHtml(oUser.login));
            this.findDialogItem('uid').text(oUser.uid);
            this.findDialogItem('longname').val(myEscapeHtml(oUser.longname));
            this.findDialogItem('email').val(myEscapeHtml(oUser.email));
            this.findDialogItem('fTicketMail').prop('checked', oUser.fTicketMail);

            // Hide the password fields
            this.findDialogItem('password-row').hide();

            // Now build oMemberships hash.
            if (oUser.groups)
                for (const strGid of oUser.groups.split(','))
                {
                    const gid = parseInt(strGid, 10);
                    // skip guests and other pseudo-groups
                    if (gid > 0)
                        this.oMemberships[gid] = true;
                }
        }
        else
        {
            // Create new user:
            this.aFieldIDs.unshift('login');        // only used in 'create' case

            this.findDialogItem('title').text(_('create-user-heading'));
            this.findDialogItem('login-div').html('<input type="text" class="form-control" placeholder="Login" id="editUserDialog-login"> </input>');
            this.findDialogItem('uid-row').hide();
            this.findDialogItem('fTicketMail').prop('checked', true);

            this.findDialogItem('generate-password').click((e) => {
                // https://stackoverflow.com/questions/9719570/generate-random-password-string-with-requirements-in-javascript
                let pwd = Math.random().toString(36).slice(-8);
                this.findDialogItem('password').val(pwd);
                return false;
            });

            // New user must be in "All users"
            this.oMemberships[oParent.gidAllUsers] = true;
        }

        this.jqGroups = this.findDialogItem('groups');
        this.jqGroups.empty();

        const aGroups = oParent.oParent.oGroupsTab.getGroups();
        for (const oGroup of aGroups)
            // skip guests and other pseudo-groups
            if (oGroup.gid > 0)
            {
                const strGid =oGroup.gid.toString();
                const checked = (this.oMemberships.hasOwnProperty(strGid) ? ' checked' : '');
                const active = (checked) ? ' active' : '';
                const htmlGname = myEscapeHtml(oGroup.gname);
                this.jqGroups.append(`<label class="btn btn-default${active}"><input type="checkbox"${checked} value="${strGid}">${htmlGname}</label>`);
            }

        this.show();
    }
}


/********************************************************************
 *
 *  ChangePasswordDialog
 *
 ********************************************************************/

class ChangePasswordDialog extends AjaxModal
{
    constructor(protected oParent: UsersTab,
                protected oUser: IUser)
    {
        super('changePasswordDialog',
              [ 'password' ],
              '/user/' + oUser.uid);

        this.method = 'PUT';

        oParent.replaceUserFields(this, oUser);
    }
}


/********************************************************************
 *
 *  DisableAccountDialog
 *
 ********************************************************************/

class DisableAccountDialog extends ConfirmDeleteTableRowDialog
{
    constructor(oParent: UsersTab,
                oUser: IUser)          //!< in: user row to be deleted
    {
        super('removeUserDialog',
              '/user/' + oUser.uid,
              oParent,
              oUser);

        oParent.replaceUserFields(this, oUser);
    }
}


/********************************************************************
 *
 *  UsersTab
 *
 ********************************************************************/

const CLASS_BUTTON_EDIT_ACCOUNT = 'drn-edit-user';
const CLASS_BUTTON_CHANGE_PASSWORD = 'drn-user-password';
const CLASS_BUTTON_DISABLE_ACCOUNT = 'drn-disable-account';

class UsersTab extends AjaxTableRenderedBase
{
    private pfnOnEditAccountClicked = (e: Event) => {
        new CreateOrEditUserDialog(this, <IUser>this.findRowDataFromClickEvent(e));
    };

    private pfnOnChangePasswordClicked = (e: Event) => {
        new ChangePasswordDialog(this, <IUser>this.findRowDataFromClickEvent(e));
    };

    private pfnOnDisableAccountClicked = (e: Event) => {
        new DisableAccountDialog(this, <IUser>this.findRowDataFromClickEvent(e));
    };

    constructor(public oParent: UsersAndGroupsPage,
                idDialog: string,
                aPlaceholders: string[],
                protected readonly uidCurrentlyLoggedIn: number,
                public readonly gidAllUsers: number)
    {
        super(idDialog,
              '/users',
              aPlaceholders,
              'uid');

        drnFindByID('create-user').click(() => {
            new CreateOrEditUserDialog(this, null);
        });

        this.fetchData();
    }

    public getUsers()
        : IUser[]
    {
        return <IUser[]>this.aRows;
    }

    /**
     *  Override the parent result to filter out the "guest" user ID.
     */
    protected doInsertRow(row: IUser)
        : boolean
    {
        return (row.uid > 0);
    }

    protected renderCell(placeholder: string,
                         row: IUser)
        : string
    {
        switch (placeholder)
        {
            case '%ID%':
                return '<td>' + row.uid.toString() + '</td>';

            case '%LOGIN%':
                return '<td>' + myEscapeHtml(row.login) + '</td>';

            case '%LONGNAME%':
                return '<td>' + myEscapeHtml(row.longname) + '</td>';

            case '%EMAIL%':
                return '<td>' + myEscapeHtml(row.email) + '</td>';

            case '%CANLOGIN%':
                return this.makeBoolCell(row.fCanLogin);

            case '%CANMAIL%':
                return this.makeBoolCell(row.fTicketMail);

            case '%CGROUPS%':
            {
                const cGroups = (row.groups) ? row.groups.split(',').length : 0;
                return '<td>' + cGroups.toString() + '</td>';
            }

            case '%ACTIONS%':
            {
                const htmlLogin = myEscapeHtml(row.login);
                const htmlLongname = myEscapeHtml(row.longname);
                const fCanChangeOrRemove = (row.uid != this.uidCurrentlyLoggedIn);

                // Edit account
                const tooltipEditUser = _('edit-user-help', { LOGIN: htmlLogin, LONGNAME: htmlLongname });
                const editUser = this.makeActionButton(CLASS_BUTTON_EDIT_ACCOUNT,
                                                       'edit',
                                                       tooltipEditUser);

                // Change password (disable button for current user)
                const tooltipChangePassword = _('change-password-help', { LOGIN: htmlLogin, LONGNAME: htmlLongname });
                const changePassword = this.makeActionButton(CLASS_BUTTON_CHANGE_PASSWORD,
                                                             'password',
                                                             tooltipChangePassword,
                                                             fCanChangeOrRemove);

                // Disable account (disable button for current user)
                const tooltipDisableAccount = _('disable-account-help', { LOGIN: htmlLogin, LONGNAME: htmlLongname });
                const disableAccount = this.makeActionButton(CLASS_BUTTON_DISABLE_ACCOUNT,
                                                             'remove',
                                                             tooltipDisableAccount,
                                                             fCanChangeOrRemove);

                return `<td>${editUser} ${changePassword} ${disableAccount}</td>`;
            }
        }

        return null;
    }

    /**
     *  Gets called after fill() has successfully inserted all table rows.
     *
     *  We override the empty parent implementation to attach event handlers to buttons.
     *
     *  Also we can handle popping up an "edit user" dialog here.
     */
    protected addHandlersToNewRow()
    {
        this.installHandlersForClass(CLASS_BUTTON_EDIT_ACCOUNT, this.pfnOnEditAccountClicked);
        this.installHandlersForClass(CLASS_BUTTON_CHANGE_PASSWORD, this.pfnOnChangePasswordClicked);
        this.installHandlersForClass(CLASS_BUTTON_DISABLE_ACCOUNT, this.pfnOnDisableAccountClicked);

        this.oParent.onChildDataLoaded(false);
    }

    /**
     *  Helper called from the child dialogs to replace placeholder strings.
     */
    public replaceUserFields(d: AjaxModal, oUser: IUser)
    {
        const htmlLogin = myEscapeHtml(oUser.login);
        const htmlLongname = myEscapeHtml(oUser.longname);
        d.findReplaceFields('drn-find-replace-user',
                            {
                                "%UID%": oUser.uid,
                                "%LOGIN%": htmlLogin,
                                "%LONGNAME%": htmlLongname
                            });
    }

    /**
     *  Called from the parent to display an edit user dialog when the edituser=uid URL param exists.
     */
    public showEditUserDialog(uid: number)
    {
        let i = this.findRowIndex(uid);
        let key = i.toString(); // array indices must be strings
        if (this.aRows.hasOwnProperty(key))
            new CreateOrEditUserDialog(this, <IUser>this.aRows[key]);
    }
}


/********************************************************************
 *
 *  CreateOrEditGroupDialog
 *
 ********************************************************************/

class CreateOrEditGroupDialog extends CreateOrEditTableRowDialog
{
    // Object abused for remembering user memberships; keys = user IDs.
    private oMembers = {};

    private jqMembers: JQuery;

    constructor(oParent: GroupsTab,
                oGroup: IGroup | null)        //!< in: group to edit; if null, then this is a "create group" dialog
    {
        super('editGroupDialog',
              [ 'gname', 'members' ],
              '/group',
              oParent,
              oGroup);

        if (oGroup)
        {
            oParent.replaceGroupFiels(this, oGroup);

            this.findDialogItem('gid').text(oGroup.gid);
            this.findDialogItem('gname').val(myEscapeHtml(oGroup.gname));

            if (oGroup.members)
                for (const strUid of oGroup.members.split(','))
                {
                    const uid = parseInt(strUid, 10);
                    // skip "guest" and other pseudo-users
                    if (uid > 0)
                        this.oMembers[uid] = true;
                }
        }
        else
        {
            this.findDialogItem('title').text(_('create-group-heading'));
            this.findDialogItem('gid-row').hide();
        }

        this.jqMembers = this.findDialogItem('members');
        this.jqMembers.empty();

        const aUsers = oParent.oParent.oUsersTab.getUsers();
        for (const oUser of aUsers)
            // skip "guest" and other pseudo-users
            if (oUser.uid > 0)
            {
                const strUid = oUser.uid.toString();
                const checked = (this.oMembers.hasOwnProperty(strUid) ? ' checked' : '');
                const active = (checked) ? ' active' : '';
                const htmlLogin = myEscapeHtml(oUser.login);
                this.jqMembers.append(`<label class="btn btn-default${active}"><input type="checkbox"${checked} value="${strUid}">${htmlLogin}</label>`);
            }

        this.show();
    }
}


/********************************************************************
 *
 *  DeleteGroupDialog
 *
 ********************************************************************/

class DeleteGroupDialog extends ConfirmDeleteTableRowDialog
{
    constructor(oParent: GroupsTab,
                oGroup: IGroup)
    {
        super('removeGroupDialog',
              '/group/' + oGroup.gid,
              oParent,
              oGroup);

        oParent.replaceGroupFiels(this, oGroup);
    }
}


/********************************************************************
 *
 *  GroupsTab
 *
 ********************************************************************/

const CLASS_BUTTON_EDIT_GROUP = 'drn-edit-group';
const CLASS_BUTTON_DELETE_GROUP = 'drn-delete-group';

class GroupsTab extends AjaxTableRenderedBase
{
    private pfnOnEditGroupClicked = (e: Event) => {
        new CreateOrEditGroupDialog(this, <IGroup>this.findRowDataFromClickEvent(e));
    };

    private pfnOnDeleteGroupClicked = (e: Event) => {
        new DeleteGroupDialog(this, <IGroup>this.findRowDataFromClickEvent(e));
    };

    constructor(public oParent: UsersAndGroupsPage,
                idDialog: string,
                aPlaceholders: string[])
    {
        super(idDialog,
              '/groups',
              aPlaceholders,
              'gid');

        drnFindByID('create-group').click(() => {
            new CreateOrEditGroupDialog(this, null);
        });

        this.fetchData();
    }

    public getGroups()
        : IGroup[]
    {
        return <IGroup[]>this.aRows;
    }

    /**
     *  Override the parent result to filter out the "nobody" group ID.
     */
    protected doInsertRow(row: IGroup)
        : boolean
    {
        return (row.gid > 0);
    }

    private getUsage(row: IGroup)
        : number
    {
        const cMembers = (row.members) ? row.members.split(',').length : 0;
        return cMembers + row.cUsedInACLs;
    }

    protected renderCell(placeholder: string,
                         row: IGroup)
        : string
    {
        switch (placeholder)
        {
            case '%ID%':
                return '<td>' + row.gid.toString() + '</td>';

            case '%GNAME%':
                return '<td>' + myEscapeHtml(row.gname) + '</td>';

            case '%CMEMBERS%':
            {
                const cMembers = (row.members) ? row.members.split(',').length : 0;
                return '<td>' + cMembers.toString() + '</td>';
            }

            case '%CUSAGE%':
                return '<td>' + this.getUsage(row).toString() + '</td>';

            case '%ACTIONS%':
            {
                const htmlGroupName = myEscapeHtml(row.gname);
                const tooltipEditGroup = _('edit-group-help', { GNAME: htmlGroupName });
                const editGroup = this.makeActionButton(CLASS_BUTTON_EDIT_GROUP,
                                                       'edit',
                                                       tooltipEditGroup);

                const tooltipDeleteGroup = _('delete-group-help', { GNAME: htmlGroupName });
                const deleteGroup = this.makeActionButton(CLASS_BUTTON_DELETE_GROUP,
                                                          'remove',
                                                          tooltipDeleteGroup,
                                                          this.getUsage(row) == 0);

                return `<td>${editGroup} ${deleteGroup}</td>`;
            }
        }

        return null;
    }

    protected addHandlersToNewRow()
    {
        this.installHandlersForClass(CLASS_BUTTON_EDIT_GROUP, this.pfnOnEditGroupClicked);
        this.installHandlersForClass(CLASS_BUTTON_DELETE_GROUP, this.pfnOnDeleteGroupClicked);

        this.oParent.onChildDataLoaded(true);
    }

    /**
     *  Helper called from the child dialogs to replace placeholder strings.
     */
    public replaceGroupFiels(d: AjaxModal, oGroup: IGroup)
    {
        const htmlGname = myEscapeHtml(oGroup.gname);
        d.findReplaceFields('drn-find-replace-group',
                            {
                                "%GID%": oGroup.gid,
                                "%GNAME%": htmlGname
                            });
    }
}


/********************************************************************
 *
 *  UsersAndGroupsView
 *
 ********************************************************************/

/**
 *  An instance of this is created by onUsersAndGroupsPageReady() below.
 */
export class UsersAndGroupsPage extends TabbedPage
{
    private idUsersTab: string;
    private idGroupsTab: string;

    public oUsersTab: UsersTab;
    public oGroupsTab: GroupsTab;

    // If this is != 0, we automatically open an "edit user" dialog on load.
    // This receives what is in the ?edituser=uid URL parameter, but only once.
    protected uidAutoEditUserOnLoad = 0;

    private fUserDataLoaded = false;
    private fGroupDataLoaded = false;

    constructor(aTabs: string[],
                aPlaceholdersUsers: string[],
                aPlaceholdersGroups: string[],
                uidCurrentlyLoggedIn: number,
                gidAllUsers: number)
    {
        super(aTabs);

        this.oUsersTab = new UsersTab(this, aTabs[0] + '-table', aPlaceholdersUsers, uidCurrentlyLoggedIn, gidAllUsers);

        this.oGroupsTab = new GroupsTab(this, aTabs[1] + '-table', aPlaceholdersGroups);

        let temp = drnGetUrlParameterInt('edituser');
        if (temp !== null)
            this.uidAutoEditUserOnLoad = temp;
    }

    /**
     *  Gets called from both the users and group tab child objects when they have finished
     *  loading data, respectively. Only if we have both data sets, we can display the
     *  "edit user" dialog from a URL parameter.
     */
    public onChildDataLoaded(fFromGroupsTab: boolean)  //!< in: whether this comes from the groups or the users tab
        : void
    {
        if (fFromGroupsTab)
            this.fGroupDataLoaded = true;
        else
            this.fUserDataLoaded = true;

        if (this.fGroupDataLoaded && this.fUserDataLoaded)
            // If we have parsed an edituser=uid URL parameter in the constructor, display edit dialog now.
            if (this.uidAutoEditUserOnLoad)
            {
                this.oUsersTab.showEditUserDialog(this.uidAutoEditUserOnLoad);
                // And don't do it again if data gets refreshed.
                this.uidAutoEditUserOnLoad = 0;
            }
    }
}

