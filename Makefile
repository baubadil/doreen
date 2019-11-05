#
# Main Doreen makefile. Requires GNU make. Tested with GNU make 3.82 on Gentoo Linux.
#
# (C) 2015--2018 Baubadil GmbH. All rights reserved.
#
# Reminders:
#  := expands at parse time, once ("simply expanded variables").
#   = expands at run-time recursively.
#

PATH_PROJECT_ROOT		:= $(shell pwd)
# $(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
DISTDIR_BASENAME                := htdocs

-include config.in

# Include dBuild core, which defines the 'all' target and much more.
include dBuild/core.in

BOOTSTRAP_ROOT                  := $(NODE_MODULES_ROOT)/bootstrap
# required for CSS
VISJS_ROOT                      := $(NODE_MODULES_ROOT)/vis
BOOTSTRAP_TABLE_ROOT            := $(NODE_MODULES_ROOT)/bootstrap-table
BOOTSTRAP_MULTISELECT_ROOT      := $(NODE_MODULES_ROOT)/bootstrap-multiselect
BOOTSTRAP_SLIDER_ROOT           := $(NODE_MODULES_ROOT)/bootstrap-slider

BOOTSTRAP_PHOTO_GALLERY_ROOT    := $(PATH_PROJECT_ROOT)/3rdparty/bootstrap-photo-gallery-master

FONTAWESOME_ROOT 	:= $(NODE_MODULES_ROOT)/font-awesome

GLOBAL_BUNDLE_CSS_FILES := $(NODE_MODULES_ROOT)/animate.css/animate.css $(FONTAWESOME_ROOT)/css/font-awesome.css

WEBPACK_ENTRY_POINTS :=
# WEBPACK_ENTRY_POINTS receives a list of module names that should become Webpack modules. 'core' is always added.
# This is added to by footer.in from each subdirectory's makefile.
ALL_TS_SOURCES := $(realpath $(shell find -L src/js -name *.ts))
# For each such Webpack module, footer.in will add .ts files to ALL_TS_SOURCES that should trigger a Webpack rebuild.
# This is added to by footer.in from each subdirectory's makefile.

#
# Locales (other than the default en_US)
#

LOCALES := de_DE

#
# Include subdirs
#

SRCDIRS 					:= core themes
# First list of plugin dirs: those that have a Makefile.in and need to be processed by this Makefile.
# Magic sauce. find -printf %h strips Makefile.in from each result, returning each directory which has a Makefile.in,
# and $(notdir ...) strips the leading directories from each resulting path, resulting in the plugin name.
PLUGINDIRS 					:= $(notdir $(shell find -L src/plugins/ -type f -name 'Makefile.in' -printf '%h '))
# Second list of plugin dirs: those that are symlinks to an external repo, whether they have a Makefile.in or not.
PLUGINS_NOT_EXTERNAL                            := type_ftdb type_vcard type_office type_school user_hardcoded user_localdb
PLUGINDIRS_REMOTE_TEMP                          := $(notdir $(shell find src/plugins -type l -xtype d))
PLUGINDIRS_REMOTE                               := $(sort $(filter-out $(PLUGINS_NOT_EXTERNAL),$(PLUGINDIRS_REMOTE_TEMP)))

MAINREPOS := .

# If ./Makefile is a symlink, then this doreen repo is a sibling to another one.
ifneq (,$(shell find . -name Makefile -type l))
MAINREPOS :=  $(MAINREPOS) $(dir $(shell readlink -f ./Makefile))
endif

EXTERNALPLUGINPATHS 	:= $(foreach PLUGIN,$(PLUGINDIRS_REMOTE),src/plugins/$(PLUGIN))
ALLREPOS := $(MAINREPOS) $(EXTERNALPLUGINPATHS)


$(call myinfo,PLUGINDIRS is $(PLUGINDIRS))

ALLDIRS = core $(PLUGINDIRS)

include $(foreach SRCDIR, $(SRCDIRS), src/$(SRCDIR)/Makefile.in)
include $(foreach PLUGINDIR, $(PLUGINDIRS), src/plugins/$(PLUGINDIR)/Makefile.in)

PHP_FILES := $(foreach SRCDIR, $(SRCDIRS), $(shell find src/$(SRCDIR) -name \*.php)) \
$(foreach PLUGINDIR, $(PLUGINDIRS), $(shell find src/plugins/$(PLUGINDIR) -name \*.php))

# Lint all php files using the built-in php linter
.PHONY: ${PHP_FILES}
${PHP_FILES}:
	php -l $@
.PHONY lint:
lint: ${PHP_FILES}


# Direct dependency of 'all' target in core.in.
mkdirs:
	$(foreach DIR, $(ALLDIRS), \
		$(shell $(MKDIR) $(PATH_OUT_JS_TEMP)/$(DIR) $(PATH_OUT_TS_TEMP)/$(DIR)))
	$(QUIET)$(MKDIR) $(PATH_OUT_BUILD_JS)
	$(QUIET)$(MKDIR) $(PATH_OUT_BUILD_CSS)
	$(QUIET)$(MKDIR) $(PATH_OUT_DIST_JS)
	$(QUIET)$(MKDIR) $(PATH_OUT_DIST_JS)/orig-src
	$(QUIET)$(MKDIR) $(PATH_OUT_DIST_CSS)
	$(QUIET)$(MKDIR) $(PATH_OUT_DIST_FONTS)
	$(QUIET)$(MKDIR) $(PATH_OUT_DIST_IMG)
	$(QUIET)$(MKDIR) $(PATH_LOCALE)
	$(foreach LOCALE, $(LOCALES), \
		$(shell $(MKDIR) $(PATH_LOCALE)/$(LOCALE)/LC_MESSAGES))


#################################################################
#
#  CSS
#
#################################################################

# We do NOT handle bootstrap CSS here since we build our own themes with less in src/themes

$(eval $(call add_global_css_target,bundle,$(GLOBAL_BUNDLE_CSS_FILES)))

$(eval $(call add_global_css_target,vis,$(VISJS_ROOT)/dist/vis.css))

$(eval $(call add_global_css_target,bootstrap-table,$(BOOTSTRAP_TABLE_ROOT)/dist/bootstrap-table.css))

$(eval $(call add_global_css_target,bootstrap-multiselect,$(BOOTSTRAP_MULTISELECT_ROOT)/dist/css/bootstrap-multiselect.css))

$(eval $(call add_global_css_target,bootstrap-photo-gallery,$(BOOTSTRAP_PHOTO_GALLERY_ROOT)/jquery.bsPhotoGallery.css))

$(eval $(call add_global_css_target,trix,$(NODE_MODULES_ROOT)/trix/dist/trix.css))

$(eval $(call add_global_css_target,bootstrap-datetimepicker,$(NODE_MODULES_ROOT)/bootstrap-datetimepicker-npm/build/css/bootstrap-datetimepicker.css))

$(eval $(call add_global_css_target,select2,$(NODE_MODULES_ROOT)/select2/dist/css/select2.css))

$(eval $(call add_global_css_target,bootstrap-slider,$(BOOTSTRAP_SLIDER_ROOT)/dist/css/bootstrap-slider.css))

$(call myinfo,GLOBAL_CSS_TARGETS is $(GLOBAL_CSS_TARGETS))

# Direct dependency of 'all' target in core.in.
css: mkdirs $(GLOBAL_CSS_TARGETS)
	$(QUIET)$(CP) $(PATH_OUT_BUILD_CSS)/*.css $(PATH_OUT_DIST_CSS)


#################################################################
#
#  Legacy JavaScript
#
#################################################################

# The following are compiled into mainbundle.js and are always loaded.
MAINBUNDLE_SOURCES := $(NODE_MODULES_ROOT)/jquery/dist/jquery.js \
	$(NODE_MODULES_ROOT)/devbridge-autocomplete/dist/jquery.autocomplete.js \
	$(NODE_MODULES_ROOT)/stupid-table-plugin/stupidtable.min.js

$(eval $(call add_global_js_target,mainbundle,$(MAINBUNDLE_SOURCES)))

$(eval $(call add_global_js_target,bootstrap-table,$(BOOTSTRAP_TABLE_ROOT)/dist/bootstrap-table.js))

MULTISEL := $(BOOTSTRAP_MULTISELECT_ROOT)/dist/js/bootstrap-multiselect.js $(BOOTSTRAP_MULTISELECT_ROOT)/dist/js/bootstrap-multiselect-collapsible-groups.js
$(eval $(call add_global_js_target,bootstrap-multiselect,$(MULTISEL)))

$(eval $(call add_global_js_target,bootstrap-photo-gallery,$(BOOTSTRAP_PHOTO_GALLERY_ROOT)/jquery.bsPhotoGallery.js))

$(eval $(call add_global_js_target,trix,$(NODE_MODULES_ROOT)/trix/dist/trix.js))

$(eval $(call add_global_js_target,wysihtml,$(NODE_MODULES_ROOT)/wysihtml/dist/wysihtml.js $(NODE_MODULES_ROOT)/wysihtml/dist/wysihtml.all-commands.js $(NODE_MODULES_ROOT)/wysihtml/dist/wysihtml.table_editing.js $(NODE_MODULES_ROOT)/wysihtml/dist/wysihtml.toolbar.js))

$(eval $(call add_global_js_target,select2,$(NODE_MODULES_ROOT)/select2/dist/js/select2.js))

# $(eval $(call add_global_js_target,autocomplete-js,))

# $(eval $(call add_global_js_target,require,$(NODE_MODULES_ROOT)/requirejs/require.js))

$(call myinfo,GLOBAL_UGLIFY_TARGETS is $(GLOBAL_UGLIFY_TARGETS))
$(call myinfo,GLOBAL_UGLIFY_TS_TARGETS is $(GLOBAL_UGLIFY_TS_TARGETS))

# Direct dependency of 'all' target in core.in.
js: mkdirs $(GLOBAL_UGLIFY_TARGETS) $(GLOBAL_UGLIFY_TS_TARGETS)
	$(QUIET)$(CP) $(PATH_OUT_BUILD_JS)/*.js $(PATH_OUT_DIST_JS)
	$(QUIET)$(CP) $(PATH_OUT_BUILD_JS)/*.map $(PATH_OUT_DIST_JS)


#################################################################
#
#  Fonts, images
#
#################################################################

# Direct dependency of 'all' target in core.in.
img_fonts: mkdirs $(GLOBAL_ALL_IMAGES)
	$(QUIET)$(CP) $(FONTAWESOME_ROOT)/fonts/* $(PATH_OUT_DIST_FONTS)


#################################################################
#
#  Webpack configuration
#
#  Webpack runs through the webpack.config.js file, but that includes the WEBPACK_EXPORT_ENTRIES_FILE file,
#  whose contents we must generate here as JSON webpack module and main input file pairs.
#  For this to work, all included makefiles add to the WEBPACK_ENTRY_POINTS and WEBPACK_ENTRY_$(MODULE) variables.
#  They also add to the ALL_TS_SOURCES variable all .ts files so that we can force a rebuild. See footer.in.
#
#################################################################

WEBPACK_EXPORT_ENTRIES_FILE := $(PATH_OUT_BASE)/webpack-export-entries.json

$(call myinfo,WEBPACK_ENTRY_POINTS $(WEBPACK_ENTRY_POINTS))

# WEBPACK_EXPORT_ENTRIES_FILE must receive something like
# echo "{ \"core\": \"/mnt/bigtera/src/doreen/src/js/entry/core.ts\", \
#   \"type_ftdb\": \"/mnt/bigtera/src/doreen/src/plugins/type_ftdb/type_ftdb.ts\", \
#   \"type_school\": \"/mnt/bigtera/src/doreen/src/plugins/type_school/type_school.ts\", \
#   \"type_vcard\": \"/mnt/bigtera/src/doreen/src/plugins/type_vcard/type_vcard.ts\" }" > $(WEBPACK_EXPORT_ENTRIES_FILE)

WEBPACK_ENTRIES_INNER_STRING := \"core\": \"$(PATH_PROJECT_ROOT)/src/js/entry/core.ts\"
COMMA =,
WEBPACK_ENTRIES_INNER_STRING += $(strip $(foreach MODULETHIS,$(WEBPACK_ENTRY_POINTS),$(COMMA) \"$(MODULETHIS)\": \"$(WEBPACK_ENTRY_$(MODULETHIS))\"))
$(call myinfo,WEBPACK_ENTRIES_INNER_STRING is $(WEBPACK_ENTRIES_INNER_STRING))

$(WEBPACK_EXPORT_ENTRIES_FILE): $(ALL_TS_SOURCES) $(GLOBALS_TRIGGERING_REBUILD)
	@echo Regenerating $(WEBPACK_EXPORT_ENTRIES_FILE)
	@echo "{$(WEBPACK_ENTRIES_INNER_STRING)}" > $(WEBPACK_EXPORT_ENTRIES_FILE)

webpack: mkdirs $(WEBPACK_EXPORT_ENTRIES_FILE)
	@echo Running webpack for modules $(WEBPACK_ENTRY_POINTS)
ifdef DEBUG
	@$(NODE_MODULES_ROOT)/.bin/webpack --hide-modules --mode development
else
	@$(NODE_MODULES_ROOT)/.bin/webpack --hide-modules --mode production
endif
	> $@


#################################################################
#
#  Translations (GNU gettext)
#
#################################################################

MO_FILES_de_DE = $(shell find -L src/core/ src/plugins/ -type f -name 'de_DE.po' )

$(call myinfo,MO_FILES_de_DE is $(MO_FILES_de_DE))

$(PATH_OUT_BASE)/de_DE_merged.po: $(MO_FILES_de_DE)
	msgcat --unique $(MO_FILES_de_DE) -o $(PATH_OUT_BASE)/de_DE_merged.po

$(PATH_LOCALE)/de_DE/LC_MESSAGES/doreen.mo: $(PATH_OUT_BASE)/de_DE_merged.po
	 msgfmt $(PATH_OUT_BASE)/de_DE_merged.po -o $(PATH_LOCALE)/de_DE/LC_MESSAGES/doreen.mo

translations: mkdirs $(PATH_LOCALE)/de_DE/LC_MESSAGES/doreen.mo

end:
	date "+%Y%m%d%H%M%S" > .lastbuild.time # Save current build time


#################################################################
#
#  Top-level targets (doc, git helpers)
#
#################################################################

doc:
	phoxygen
	cd doc/latex && pdflatex doreen && pdflatex doreen && pdflatex doreen

pull:
	@echo Pushing to repos in $(ALLREPOS)
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Pulling from repo $$r; cd $$r; git remote | xargs -L1 git pull; cd $(PATH_PROJECT_ROOT); done;
	make -B -j8

push:
	make -B -j8
	php cli/cli.php --install-dir /var/www/localhost/ run-tests
# 	tools/bump-version.pl version.inc
	@echo Pushing to repos in $(ALLREPOS)
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Pushing to repo $$r; cd $$r; git remote | xargs -L1 git push; cd $(PATH_PROJECT_ROOT); done;

status:
	@echo Showing status of repos in $(ALLREPOS)
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Status of repo $$r; cd $$r; git status -sb; cd $(PATH_PROJECT_ROOT); done;

master:
	@echo Switching repos to master: $(ALLREPOS)
#	@for r in $(ALLREPOS); do cd $$r; if [ "$$(git rev-parse --abbrev-ref HEAD)" != 'develop' ]; then echo "ERROR: repo $$r not in develop branch"; exit 2; fi; cd $(PATH_PROJECT_ROOT); done;
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Switch to master in repo $$r; cd $$r; git checkout master || exit 2; cd $(PATH_PROJECT_ROOT); done;

develop:
	@echo Switching repos to develop: $(ALLREPOS)
#	@for r in $(ALLREPOS); do cd $$r; if [ "$$(git rev-parse --abbrev-ref HEAD)" != 'master' ]; then echo "ERROR: repo $$r not in master branch"; exit 2; fi; cd $(PATH_PROJECT_ROOT); done;
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Switch to develop in repo $$r; cd $$r; git checkout develop || exit 2; cd $(PATH_PROJECT_ROOT); done;

merge-develop:
	@for r in $(ALLREPOS); do cd $$r; if [ "$$(git rev-parse --abbrev-ref HEAD)" != 'master' ]; then echo "ERROR: repo $$r not in master branch"; exit 2; fi; cd $(PATH_PROJECT_ROOT); done;
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Merging develop into master in repo $$r; cd $$r; git merge develop || exit 2; cd $(PATH_PROJECT_ROOT); done;

merge-master:
	@for r in $(ALLREPOS); do cd $$r; if [ "$$(git rev-parse --abbrev-ref HEAD)" != 'develop' ]; then echo "ERROR: repo $$r not in develop branch"; exit 2; fi; cd $(PATH_PROJECT_ROOT); done;
	@for r in $(ALLREPOS); do echo $( ); echo \*\*\*\*\*\* Merging master into develop in repo $$r; cd $$r; git merge master || exit 2; cd $(PATH_PROJECT_ROOT); done;

fetch-lab:
	@for r in $(ALLREPOS); do echo $( ); cd $$r; if [ "$$(git remote | grep lab)" ]; then echo \*\*\*\*\*\* Fetching lab for repo $$r; git fetch lab || exit 2; else echo \*\*\*\*\*\* Repo $$r has no lab remote, skipping; fi; cd $(PATH_PROJECT_ROOT); done;

log-lab:
ifndef LABBRANCH
	$(error Please define LABBRANCH in config.in)
endif
	git log HEAD..lab/$(LABBRANCH)

diff-lab:
ifndef LABBRANCH
	$(error Please define LABBRANCH in config.in)
endif
	git diff HEAD..lab/$(LABBRANCH)

merge-lab:
ifndef LABBRANCH
	$(error Please define LABBRANCH in config.in)
endif
	@for r in $(ALLREPOS); do cd $$r; if [ "$$(git rev-parse --abbrev-ref HEAD)" != 'develop' ]; then echo "ERROR: repo $$r not in develop branch"; exit 2; fi; cd $(PATH_PROJECT_ROOT); done;
	@for r in $(ALLREPOS); do echo $( ); cd $$r; if [ "$$(git rev-parse --verify lab/$(LABBRANCH))" ]; then echo \*\*\*\*\*\* Merging lab/$(LABBRANCH) in repo $$r; git merge lab/$(LABBRANCH) || exit 2; else echo \*\*\*\*\*\* Repo $$r has no lab remote, skipping; fi; cd $(PATH_PROJECT_ROOT); done;
	make -B -j8

status-all:
	@echo ----------------\> STATUS FOR MAIN REPO
	git status
	@$(foreach plugin,$(PLUGINDIRS_REMOTE),echo && echo ----------------\> STATUS FOR $(plugin) && cd src/plugins/$(plugin) && git status && cd $(PATH_PROJECT_ROOT);)

help:
	@echo Useful targets:
	@echo -- all
	@echo -- doc
	@echo -- status \ \ \ \ \ \ \ \ \(run \"git status\" for \$$ALLREPOS\)
	@echo -- pull \ \ \ \ \ \ \ \ \ \ \(run \"git pull\" for each repo\)
	@echo -- push \ \ \ \ \ \ \ \ \  \(run \"git push\" for each repo\)
	@echo -- status-all \ \ \ \ \(run \"git status\" for each repo\)
	@echo -- master \ \ \ \ \ \ \ \ \(check out \"master\" branch for each repo\)
	@echo -- develop \ \ \ \ \ \ \ \(check out \"develop\" branch for each repo\)
	@echo -- merge-develop \ \(merge develop into master for each repo\)
	@echo -- merge-master \ \ \(merge master into develop for each repo\)
	@echo -- fetch-lab \ \ \ \ \ \(fetch multiple remote branches into master\)
	@echo -- log-lab \ \ \ \ \ \ \ \(define LABBRANCH in config.in\)
	@echo -- diff-lab \ \ \ \ \ \ \(define LABBRANCH in config.in\)
	@echo -- merge-lab \ \ \ \ \ \(merge LABBRANCH for each repo\; define LABBRANCH in config.in\)
	@echo -- lint \ \ \ \ \ \ \ \ \ \ \(run \"php -l\" on all PHP files\)
	@echo \$$ALLREPOS is: $(ALLREPOS)
