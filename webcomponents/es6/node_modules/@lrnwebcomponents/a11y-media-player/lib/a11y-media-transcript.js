import{html,PolymerElement}from"../../../@polymer/polymer/polymer-element.js";import{A11yMediaPlayerProperties}from"./a11y-media-player-properties.js";import"./a11y-media-transcript-cue.js";export{A11yMediaTranscript};class A11yMediaTranscript extends A11yMediaPlayerProperties{static get properties(){return{activeCues:{type:Array,value:null,reflectToAttribute:!0,notify:!0},lang:{type:String,value:"en",reflectToAttribute:!0},mediaId:{type:String,value:null},tabIndex:{type:Number,computed:"_getTabIndex(disableInteractive)"},role:{type:Number,computed:"_getRole(disableInteractive)"},selectedTranscript:{type:String,value:"0"},tracks:{type:Array,value:null}}}static get tag(){return"a11y-media-transcript"}static get behaviors(){return[A11yMediaPlayerProperties]}static get template(){return html`
      <style is="custom-style" include="simple-colors">
        :host {
          display: block;
          padding: 15px;
          color: var(--a11y-media-transcript-color);
          background-color: var(--a11y-media-transcript-bg-color);
        }
        :host([hidden]) {
          display: none;
        }
        :host #inner {
          width: 100%;
          display: none;
        }
        :host #inner[active] {
          display: table;
          width: 100%;
        }
        :host #inner[active][hideTimestamps] {
          display: block;
        }
        :host .sr-only:not(:focus) {
          position: absolute;
          left: -99999;
          top: 0;
          height: 0;
          width: 0;
          overflow: hidden;
        }
        @media print {
          :host {
            padding: 0 15px 5px;
            color: #000;
            background-color: #ffffff;
            border-top: 1px solid #aaa;
          }
        }
      </style>
      <a id="transcript-desc" href="#bottom" class="sr-only"
        >[[skipTranscriptLink]]</a
      >
      <template id="tracks" is="dom-repeat" items="{{tracks}}" as="track">
        <div
          id="inner"
          class="transcript-from-track"
          lang="{{track.language}}"
          active$="[[track.active]]"
        >
          <template is="dom-repeat" items="{{track.cues}}" as="cue">
            <a11y-media-transcript-cue
              accent-color$="[[accentColor]]"
              active-cues$="[[activeCues]]"
              controls$="[[mediaId]]"
              cue$="{{cue}}"
              disabled$="[[disableInteractive]]"
              disable-search$="[[disableSearch]]"
              hide-timestamps$="[[hideTimestamps]]"
              on-tap="_handleCueSeek"
              order$="{{cue.order}}"
              role="button"
              search="[[search]]"
              tabindex="0"
            >
            </a11y-media-transcript-cue>
          </template>
        </div>
      </template>
      <div id="bottom" class="sr-only"></div>
    `}connectedCallback(){super.connectedCallback();this.dispatchEvent(new CustomEvent("transcript-ready",{detail:this}))}ready(){super.ready()}setMedia(player){this.media=player;this.dispatchEvent(new CustomEvent("transcript-ready",{detail:this}))}toggleHidden(mode){let root=this,inner=document.getElementById("inner"),active=null!==inner&&inner!==void 0?inner.querySelector("a11y-media-transcript-cue[active]"):null,first=null!==inner&&inner!==void 0?inner.querySelector("a11y-media-transcript-cue"):null;mode=mode!==void 0?mode:this.hidden;this.hidden=mode}print(mediaTitle){let root=this,track=root.shadowRoot.querySelector("#inner[active]").cloneNode(!0),css=html`
        <style>
          a11y-media-transcript-cue {
            display: table-row;
            background-color: #fff;
            color: #000;
          }
          a11y-media-transcript-cue[hide-timestamps],
          a11y-media-transcript-cue[hide-timestamps] #text {
            display: inline;
          }
          a11y-media-transcript-cue #text {
            display: table-cell;
            line-height: 200%;
          }
          a11y-media-transcript-cue #time {
            display: table-cell;
            font-size: 80%;
            padding: 0 16px;
            white-space: nowrap;
            font-family: monospace;
          }
          a11y-media-transcript-cue[hide-timestamps] #time {
            display: none;
          }
          a11y-media-transcript-cue [matched] {
            background-color: #fff;
            color: #eee;
            padding: 3px 4px;
            border-radius: 3px;
          }
        </style>
      `,h1=html`
        <h1>Transcript</h1>
      `;if(mediaTitle!==void 0)h1.innerHTML=mediaTitle;if(null!==track&track!==void 0){let print=window.open("","","left=0,top=0,width=552,height=477,toolbar=0,scrollbars=0,status =0");print.document.body.appendChild(css);print.document.body.appendChild(h1);print.document.body.appendChild(track);print.document.close();print.focus();print.print();print.close()}}setTracks(tracks){this.set("tracks",tracks.slice(0));this.notifyPath("tracks");if(this.tracks!==void 0&&0<this.tracks.length)this.$.tracks.render()}setActiveCues(cues){let root=this,offset=null!==root.shadowRoot.querySelector("#inner")&&root.shadowRoot.querySelector("#inner")!==void 0?root.shadowRoot.querySelector("#inner").offsetTop:0,cue=root.shadowRoot.querySelector("#inner a11y-media-transcript-cue[active]");root.set("activeCues",cues.slice(0));if(!root.disableScroll&&null!==cue&cue!==void 0){let scrollingTo=function(element,to,duration){if(0>=duration)return;var difference=to-element.scrollTop;setTimeout(function(){element.scrollTop=element.scrollTop+10*(difference/duration);if(element.scrollTop===to)return;scrollingTo(element,to,duration-10)},10)};scrollingTo(root,cue.offsetTop-offset,250)}}_getTabIndex(disableInteractive){return disableInteractive?-1:0}_getRole(disableInteractive){return disableInteractive?null:"button"}_handleCueSeek(e){if(!this.disableInteractive){this.dispatchEvent(new CustomEvent("cue-seek",{detail:e.detail}))}}setActiveTranscript(index){if(this.tracks!==void 0&&null!==this.tracks){for(let i=0;i<this.tracks.length;i++){if(parseInt(index)===i){this.selectedTranscript=parseInt(index);this.set("tracks."+i+".active",!0)}else if(null!==this.tracks[i]){this.set("tracks."+i+".active",!1)}this.notifyPath("tracks."+i+".active")}}this.$.tracks.render()}}window.customElements.define(A11yMediaTranscript.tag,A11yMediaTranscript);