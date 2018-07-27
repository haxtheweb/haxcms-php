<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
?>
<link rel="import" href="bower_components/polymer/polymer.html">
<link rel="import" href="bower_components/iron-ajax/iron-ajax.html">
<link rel="import" href="bower_components/hax-body/hax-store.html">
<link rel="import" href="bower_components/hax-body/hax-body.html">
<link rel="import" href="bower_components/hax-body/hax-autoloader.html">
<link rel="import" href="bower_components/hax-body/hax-manager.html">
<link rel="import" href="bower_components/hax-body/hax-app-picker.html">
<link rel="import" href="bower_components/hax-body/hax-app.html">
<link rel="import" href="bower_components/hax-body/hax-panel.html">
<link rel="import" href="bower_components/hax-body/hax-export-dialog.html">
<link rel="import" href="bower_components/hax-body/hax-toolbar.html">
<link rel="import" href="bower_components/paper-fab/paper-fab.html">
<link rel="import" href="bower_components/paper-tooltip/paper-tooltip.html">
<link rel="import" href="bower_components/iron-icons/editor-icons.html">
<link rel="import" href="bower_components/paper-toast/paper-toast.html">
<link rel="import" href="hax-cms-outline-editor-dialog.html">
<!--
`hax-cms-site-editor`
PHP based haxcms editor element

@demo demo/index.html

@microcopy - the mental model for this element

-->

<dom-module id="hax-cms-site-editor">
  <template>
    <style>
      :host {
        display: block;
      }
      #editbutton {
        position: fixed;
        bottom: 0;
        right: 0;
        margin: 16px;
        padding: 8px;
        width: 30px;
        height: 30px;
        visibility: visible;
        opacity: 1;
        transition: all .4s ease;
      }
      #outlinebutton {
        position: fixed;
        bottom: 0;
        right: 46px;
        margin: 16px;
        padding: 8px;
        width: 30px;
        height: 30px;
        visibility: visible;
        opacity: 1;
        transition: all .4s ease;
      }
      :host[edit-mode] #editbutton {
        width: 100%;
        z-index: 100;
        right: 0;
        bottom: 0;
        border-radius: 0;
        height: 80px;
        margin: 0;
        padding: 8px;
        background-color: var(--paper-blue-500) !important;
      }
      hax-body {
        padding: 48px;
        max-width: 1040px;
        margin: auto;
        display: none;
      }
      :host[edit-mode] hax-body {
        display: block;
      }
    </style>
    <iron-ajax
     headers='{"Authorization": "Bearer [[jwt]]"}'
     id="pageupdateajax"
     url="<?php print $HAXCMS->basePath . 'system/savePage.php';?>"
     method="POST"
     body="[[updatePageData]]"
     content-type="application/json"
     handle-as="json"
     on-response="_handlePageResponse"></iron-ajax>
    <iron-ajax
     headers='{"Authorization": "Bearer [[jwt]]"}'
     id="outlineupdateajax"
     url="<?php print $HAXCMS->basePath . 'system/saveOutline.php';?>"
     method="POST"
     body="[[updateOutlineData]]"
     content-type="application/json"
     handle-as="json"
     on-response="_handleOutlineResponse"></iron-ajax>
    <hax-store app-store='<?php print json_encode($HAXCMS->appStoreConnection());?>'?></hax-store>
    <hax-app-picker></hax-app-picker>
    <hax-body></hax-body>
    <hax-autoloader hidden></hax-autoloader>
    <hax-panel align="left" hide-panel-ops></hax-panel>
    <hax-manager append-jwt="jwt" id="haxmanager"></hax-manager>
    <hax-export-dialog></hax-export-dialog>
    <paper-fab id="editbutton" icon="[[__editIcon]]"></paper-fab>
    <paper-tooltip for="editbutton" position="top" offset="14">[[__editText]]</paper-tooltip>
    <paper-fab id="outlinebutton" icon="icons:list"></paper-fab>
    <paper-tooltip for="outlinebutton" position="top" offset="14">edit outline</paper-tooltip>
    <hax-cms-outline-editor-dialog id="dialog" items="[[manifest.items]]"></hax-cms-outline-editor-dialog>
    <paper-toast id="toast"></paper-toast>
  </template>
  <script>
    Polymer({
      is: 'hax-cms-site-editor',
      listeners: {
        'editbutton.tap': '_editButtonTap',
        'outlinebutton.tap': '_outlineButtonTap',
        'dialog.save-outline': 'saveOutline',
      },
      properties: {
        /**
         * JSON Web token, it'll come from a global call if it's available
         */
        jwt: {
          type: String,
        },
        /**
         * if the page is in an edit state or not
         */
        editMode: {
          type: Boolean,
          reflectToAttribute: true,
          observer: '_editModeChanged',
          value: false,
        },
        /**
         * Outline editing state
         */
        outlineEditMode: {
          type: Boolean,
          reflectToAttribute: true,
          observer: '_outlineEditModeChanged',
          value: false,
        },
        /**
         * data as part of the POST to the backend
         */
        updatePageData: {
          type: Object,
          value: {},
        },
        /**
         * data as part of the POST to the backend
         */
        updateOutlineData: {
          type: Object,
          value: {},
        },
        /**
         * Active item of the page being worked on, JSON outline schema item format
         */
        activeItem: {
          type: Object,
          value: {},
        },
        /**
         * Outline of items in json outline schema format
         */
         manifest: {
           type: Object,
         },
      },
      /**
       * Reaady life cycle
       */
      created: function () {
        document.body.addEventListener('json-outline-schema-active-item-changed', this._newActiveItem.bind(this));      
        document.body.addEventListener('json-outline-schema-changed', this._manifestChanged.bind(this));      
        document.body.addEventListener('json-outline-schema-active-body-changed', this._bodyChanged.bind(this));
      },
      /**
       * Reaady life cycle
       */
      ready: function () {
        document.body.appendChild(this.$.dialog);
        document.body.appendChild(this.$.toast);
      },
      /**
       * attached life cycle
       */
      attached: function () {
        this.$.toast.show('You are logged in, edit tools shown.');
      },
      /**
       * react to manifest being changed
       */
      _manifestChanged: function (e) {
        this.set('manifest', []);
        this.set('manifest', e.detail);
      },
      /**
       * update the internal active item
       */
       _newActiveItem: function (e) {
        this.set('activeItem', e.detail);
        let parts = window.location.pathname.split('/');
        parts.pop();
        let site = parts.pop();
        // set upload manager to point to this location in a more dynamic fashion
        this.$.haxmanager.appendUploadEndPoint = 'siteName=' + site + '&page=' + e.detail.id;
       },
      /**
       * toggle state on button tap
       */
      _editButtonTap: function (e) {
        this.editMode = !this.editMode;
      },
      /**
       * toggle state on button tap
       */
      _outlineButtonTap: function (e) {
        this.outlineEditMode = !this.outlineEditMode;
      },
      /**
       * handle update responses for pages and outlines
       */
       _handlePageResponse: function (e) {
          this.$.toast.show('Page saved!');
       },
       _handleOutlineResponse: function (e) {
         // trigger a refresh of the data in page
         Polymer.cmsSiteEditor.instance.appElement[Polymer.cmsSiteEditor.instance.appRefreshCallback]();
          this.$.toast.show('Outline saved!');
       },
      /**
       * Edit state has changed.
       */
      _editModeChanged: function (newValue, oldValue) {
        if (newValue) {
          // enable it some how
          this.__editIcon = 'icons:save';
          this.__editText = 'Save';
        }
        else {
          // disable it some how
          this.__editIcon = 'editor:mode-edit';
          this.__editText = 'edit page';
        }
        this.fire('edit-mode-changed', newValue);
        Polymer.HaxStore.write('editMode', newValue, this);
        // was on, now off
        if (!newValue && oldValue) {
          let parts = window.location.pathname.split('/');
          parts.pop();
          let site = parts.pop();
          this.set('updatePageData.siteName', site);
          this.set('updatePageData.body', Polymer.HaxStore.instance.activeHaxBody.haxToContent());
          this.set('updatePageData.page', this.activeItem.id);
          this.set('updatePageData.jwt', this.jwt);
          // send the request
          this.$.pageupdateajax.generateRequest();
        }
      },
      /**
       * Note changes to the outline / structure of the page's items
       */
      _outlineEditModeChanged: function (newValue, oldValue) {
        if (newValue) {
          this.$.dialog.opened = true;
        }
        else {
          this.$.dialog.opened = false;
        }
        this.fire('outline-edit-mode-changed', newValue);
      },
      /**
       * Save the outline based on an event firing.
       */
      saveOutline: function (e) {
        let parts = window.location.pathname.split('/');
        parts.pop();
        let site = parts.pop();
        // now let's work on the outline
        this.set('updateOutlineData.siteName', site);
        this.set('updateOutlineData.items', e.detail);
        this.set('updateOutlineData.jwt', this.jwt);
        this.$.outlineupdateajax.generateRequest();
      },
      /**
       * Notice body of content has changed and import into HAX
       */
      _bodyChanged: function (e) {
        Polymer.HaxStore.instance.activeHaxBody.importContent(e.detail);
      },
    });
  </script>
</dom-module>