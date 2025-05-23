/**
 * Copyright 2025 NazmanRosman
 * @license Apache-2.0, see LICENSE for full text.
 */
import{html as o,css as t}from"../../lit/index.js";import{HAXCMSLitElementTheme as s}from"../haxcms-elements/lib/core/HAXCMSLitElementTheme.js";import{store as i}from"../haxcms-elements/lib/core/haxcms-site-store.js";import{autorun as r,toJS as e}from"../../mobx/dist/mobx.esm.js";import{DDDSuper as l}from"../d-d-d/d-d-d.js";import{I18NMixin as p}from"../i18n-manager/lib/I18NMixin.js";import"./lib/glossy-portfolio-card.js";import"./lib/glossy-portfolio-header.js";import"./lib/glossy-portfolio-page.js";import"./lib/glossy-portfolio-home.js";import"./lib/glossy-portfolio-grid.js";import"./lib/glossy-portfolio-about.js";export class GlossyPortfolioTheme extends(l(p(s))){static get tag(){return"glossy-portfolio-theme"}constructor(){super(),this.title="",this.currentView="home",this.t=this.t||{},this.t={...this.t,title:"Title"}}static get properties(){return{...super.properties,title:{type:String},currentView:{type:String}}}disconnectedCallback(){if(this.__disposer)for(var o in this.__disposer)this.__disposer[o].dispose();super.disconnectedCallback()}static get styles(){return[super.styles,t`
      :host{
        --bg-color: #111111;
        --main-font: "Manrope", "Manrope Placeholder", sans-serif;
        --max-width: 1200px;
        --page-padding: 0 25px;
        --mobile-page-padding: 0 15px;
        
    
      }
 
      
      :host {
        display: block;
        color: var(--ddd-theme-primary);
        background-color: var(--bg-color);
        font-family: var(--main-font);
        margin: auto;
        box-sizing: border-box;
        overflow: visible;
        min-height: 100vh;
      }
    `]}render(){if("home"===this.currentView)return o`
      <div id="contentcontainer">
        <div id="slot"><slot></slot></div>
      </div>
      <glossy-portfolio-home></glossy-portfolio-home>
      <!-- <glossy-portfolio-about></glossy-portfolio-about> -->
      <!-- <glossy-portfolio-page></glossy-portfolio-page> -->
      <!-- <glossy-portfolio-grid class="projects"></glossy-portfolio-grid> -->

      `}static get haxProperties(){return new URL(`./lib/${this.tag}.haxProperties.json`,import.meta.url).href}}globalThis.customElements.define(GlossyPortfolioTheme.tag,GlossyPortfolioTheme);