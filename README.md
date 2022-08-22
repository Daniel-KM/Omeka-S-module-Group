Group (module for Omeka S)
==========================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Group] is a module for [Omeka S] that allows to set groups to users, as in many
authentication systems, and to set the same groups to any resources, so their
visibility can be managed in a more flexible way.

In the admin interface, this module decreases the access of the identified users
to the resources, and, in the public interface, with the module [Guest User], it
increases the access of guest users to private resources.

So, it doesn’t replace the main public/private rule, but adds another level of
rules for visibility. For example, a user who belongs to group "Alpha" can
access all items that have at least this group in common, and, of course, all
items that are public.

The item sets, the items and the media without group follow the default rules.
The admins, the editors and the reviewers have always access to all resources.
The rules are not changed for visitors (access to public resources only).

In practice, this module is usefull only for sites that need to manage finely
the access to resources for researchers, authors and guests. For other roles,
you have to unset the `view-all` right via another module or via a contribution.


Installation
------------

Uncompress files and rename module folder "Group".

See general end user documentation for [Installing a module].


Usage
-----

The groups (names) are manageable directly in the admin view. They can be
assigned to users and resources (item sets, items, medias) in their respective
views. They are available via the api too.

For the resources, the groups can be managed in three ways:

- individually;
- recursively for medias (the rights of the item are applied to all medias);
- fully recursively (the rights of item sets will apply to all items and medias).

By default, the groups are managed fully recursively, so when a group is
assigned to an item set, all items that belong to this item set will be assigned
to this  group too. The same for items for media, and the same for unassignment.
When an item belong to multiple collections, all groups of all its collections
are assigned.

A change to the settings applies only to newly saved resources. There is no bulk
tool to process existing resources, but the groups are updated each time they
are saved. To set this option, copy it with its direct hierarchy from the file
`config/module.config.php` of the module into your `config/local.config.php`:
`['group']['config']['group_recursive_item_sets']` and `['group']['config']['group_recursive_items']`.


Access rights
-------------

- Visitors
    - No access to private resources
    - No access to any group of resources
    - So access to public resources only (default acl rules)
- Guests, Researchers and Authors
    - No access to private resources
    - Except access to own resources (Authors)
    - Except access to the resources when one of the resources groups match one
    of their own groups
    - Users without groups haven’t access to more resources
    - Private resources without groups are not visible.
- Admins, editors and reviewers
    - Access to all private resources (default acl rules "view-all")

The rights of admin, editors and reviewers can be restricted too: simply remove
the right `view-all` for them in the access control lists (acl). In that case,
it is recommended not to change the rights of the admins, or to set a group
"staff", for example, and to assign this group to all admins and resources
before.


TODO
----

- [ ] Remove complicate processes used to bypass some Omeka < 2.0 issues.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2017-2020 (see [Daniel-KM] on GitLab)


[Group]: https://gitlab.com/Daniel-KM/Omeka-S-module-Group
[Omeka S]: https://omeka.org/s
[Guest User]: https://github.com/biblibre/omeka-s-module-GuestUser
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Group/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
