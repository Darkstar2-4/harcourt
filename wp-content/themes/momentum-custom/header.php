<!DOCTYPE html>
<html class="no-js" <?php language_attributes(); ?>>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php wp_enqueue_script("jquery"); ?>
  <?php wp_head(); ?>
  <link rel='stylesheet' id='gforms_formsmain_css-css'  href='/wp-content/plugins/gravityforms/css/formsmain.min.css' type='text/css' media='all' />
  <link rel='stylesheet' id='gforms_ready_class_css-css'  href='/wp-content/plugins/gravityforms/css/readyclass.min.css' type='text/css' media='all' />
  <link rel='stylesheet' id='gforms_browsers_css-css'  href='/wp-content/plugins/gravityforms/css/browsers.min.css' type='text/css' media='all' />
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.0.0-beta.3/assets/owl.carousel.min.css">
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.0.0-beta.3/assets/owl.theme.default.min.css">
  <link rel="stylesheet" type="text/css" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
  <script type='text/javascript' src='/wp-content/plugins/gravityforms/js/jquery.json-1.3.js'></script>
  <script type='text/javascript' src='/wp-content/plugins/gravityforms/js/gravityforms.min.js'></script>
  <script type="text/javascript" src="/scs_scripts/scscpq_wp.js?ver=20261029a"></script>
  <script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
  <style media="screen">
  body { display: flex; min-height: 100vh; flex-direction: column; font-size: 1.125rem; font-family: 'Open Sans'}
  main { flex: 1 0 auto; }
  .site-header-image { display: block; margin: auto; }
  .alignleft { float: left; }
  .alignright { float: right; }
  .aligncenter { clear: both; display: block; }
  img, .size-auto, .size-full, .size-large, .size-medium, .size-thumbnail { max-width: 100%; height: auto; }
  .author-bio .avatar { border: 1px solid #dee2e6; border-radius: 50%; }
  input:focus::-webkit-input-placeholder { color: transparent; }
  input:focus:-moz-placeholder { color: transparent; } /* FF 4-18 */
  input:focus::-moz-placeholder { color: transparent; } /* FF 19+ */
  textarea:focus::-webkit-input-placeholder { color: transparent; }
  textarea:focus:-moz-placeholder { color: transparent; } /* FF 4-18 */
  textarea:focus::-moz-placeholder { color: transparent; } /* FF 19+ */
  #comments, #reply-title { margin-top: 1.5rem; margin-bottom: 1rem; }
  .commentlist, .commentlist ul { padding-left: 0; }
  .commentlist li { padding: 1rem; }
  .comment-meta { margin-bottom: 1rem; }
  .commentlist .children { margin-top: 1rem; }
  .container {max-width: 1400px;}
  a:hover { transition: ease all .3s; color:#DE2337;}
  .white { color:#fff;}
  section {overflow: hidden;}
  .h1,.h2,.h3,.h4,.h5,.h6,
  h1,h2,h3,h4,h5,h6 {font-family: 'Rajdhani'; font-weight: 600; line-height: 1.3;}
  h1.white,h2.white,h3.white,h4.white,h5.white,h6.white {color:#fff !important}
  .h1, h1 {font-weight: 700; font-size: 2.5rem; }
  .h2, h2 {font-weight: 700; font-size: 2.5rem; }
  .h3, h3 {font-weight: 700; font-size: 1.5rem; }
  .h4, h4 {font-weight: 700; font-size: 1.25rem; }
  .h5, h5 {font-weight: 700; font-size: 1.125rem; }
  #content .h1, #content h1 { margin-bottom: 2.5rem; }
  #content .h2, #content h2 { margin-top: 2.5rem; margin-bottom: 1.25rem}
  #content .h3, #content h3 { margin-top: 2.5rem; margin-bottom: 1.25rem}
  #content .h4, #content h4 { margin-top: 1.75rem; margin-bottom: .925rem}
  #content .h5, #content h5 { margin-top: 1.75rem; margin-bottom: .925rem}
  .widget_text h3:first-child { margin-top:0 ; margin-bottom: .925rem; }
  .widget_text h4,
  .widget_text h5 { margin-top: 1.5rem ; margin-bottom: .25rem; color: #DE2337; }
  .home-hero h1 { font-size: 3.25rem;}
  .site-padding {padding: 100px 0;}
  .site-padding-bl {padding: 100px 0 0;}
  .site-padding-tl {padding:0 0 100px;}
  @media only screen and ( max-width : 1200px ) {
    .h1, h1 {font-size: 2rem; }
    .h2, h2 {font-size: 2rem; }
    .h3, h3 {font-size: 1.25rem; }
    .h4, h4 {font-size: 1.125rem; }
    .h5, h5 {font-size: 1rem; }
    .home-hero h1 { font-size: 3rem;}
    .site-padding {padding: 80px 0;}
    .site-padding-bl {padding: 80px 0 0;}
    .site-padding-tl {padding:0 0 80px;}
  }
  @media only screen and ( max-width : 991px ) {
    .h1, h1 {font-size: 1.5rem; }
    .h2, h2 {font-size: 1.5rem; }
    .h3, h3 {font-size: 1.125rem; }
    .h4, h4 {font-size: 1.0325rem; }
    .home-hero h1 { font-size: 2.5rem;}
    .site-padding {padding: 75px 0;}
    .site-padding-bl {padding: 75px 0 0;}
    .site-padding-tl {padding:0 0 75px;}
    body { font-size: 1rem }
  }
  @media only screen and ( max-width : 768px ) {
    .h1, h1 {font-size: 1.325rem; }
    .h2, h2 {font-size: 1.325rem; }
    .h3, h3 {font-size: 1.25rem; }
    .home-hero h1 { font-size: 2rem;}
    .site-padding {padding: 60px 0;}
    .site-padding-bl {padding: 60px 0 0;}
    .site-padding-tl {padding:0 0 60px;}
  }
  @media only screen and ( max-width : 576px ) {
    body { font-size: .925rem }
  }
  /* General styles for all menus */
  @media only screen and ( max-width : 1200px ) {
    .scscpq_search { padding: 1rem; order: 2; }
    .scscpq_search input { width: 160px; }
    li#menu-item-141 { margin-top: 0; }
    .scscpq_search input + img { position: relative; top: -2px; }
    .cbp-spmenu { background: rgb(0,0,0); background: radial-gradient(circle, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%); position: fixed; display: flex !important;}
    .cbp-spmenu h3 { color: #afdefa; font-size: 1.9em; padding: 20px; margin: 0; font-weight: 300; background: #0d77b6; }
    .cbp-spmenu a { display: block; color: #fff !important; cursor: pointer; font-size: 1.1em; font-weight: 300; }
    .cbp-spmenu a:hover { background: #258ecd; }
    .cbp-spmenu a:active { background: #afdefa; color: #47a3da; }
    /* Orientation-dependent styles for the content of the menu */
    .cbp-spmenu-vertical { width: 280px; height: 100%; top: 0; z-index: 1000; overflow-y: hidden; }
    .cbp-spmenu-vertical a { border-bottom: 1px solid rgba(0,0,0,.8); padding: 1em; }
    .cbp-spmenu-horizontal { width: 100%; height: 150px; left: 0; z-index: 1000; overflow: hidden; }
    .cbp-spmenu-horizontal h3 { height: 100%; width: 20%; float: left; }
    .cbp-spmenu-horizontal a { float: left; width: 20%; padding: 0.8em; border-left: 1px solid #258ecd; }
    /* Vertical menu that slides from the left or right */
    .cbp-spmenu-left { left: -300px; }
    .cbp-spmenu-right { right: -280px; }
    .cbp-spmenu-left.menu-open { left: 0px; }
    .cbp-spmenu-right.menu-open { right: 0px; }
    /* Horizontal menu that slides from the top or bottom */
    .cbp-spmenu-top { top: -150px; }
    .cbp-spmenu-bottom { bottom: -150px; }
    .cbp-spmenu-top.menu-open { top: 0px; }
    .cbp-spmenu-bottom.menu-open { bottom: 0px; }
    /* Push classes applied to the body */
    .push-body { overflow-x: hidden; position: relative; left: 0; }
    .push-body-toright { left: 280px; }
    .push-body-toleft { left: -280px; }
    /* Transitions */
    .cbp-spmenu,
    .push-body { -webkit-transition: all 0.3s ease; -moz-transition: all 0.3s ease; transition: all 0.3s ease; }
    nav.navbar {background: rgb(0,0,0); /*background: linear-gradient(270deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%);*/}
    #nav-icon { width: 40px; height: 34px; position: relative; margin: 20px 0; -webkit-transform: rotate(0deg); -moz-transform: rotate(0deg); -o-transform: rotate(0deg); transform: rotate(0deg); -webkit-transition: .5s ease-in-out; -moz-transition: .5s ease-in-out; -o-transition: .5s ease-in-out; transition: .5s ease-in-out; cursor: pointer; overflow: visible; z-index: 999999; float: right; }
    #nav-icon span { display: block; position: absolute; height: 4px; width: 100%;  background: rgb(181,181,181); background: linear-gradient(270deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%); border-radius: 0px; opacity: 1; left: 0; -webkit-transform: rotate(0deg); -moz-transform: rotate(0deg); -o-transform: rotate(0deg); transform: rotate(0deg); -webkit-transition: .25s ease-in-out; -moz-transition: .25s ease-in-out; -o-transition: .25s ease-in-out; transition: .25s ease-in-out; }
    #nav-icon span:nth-child(1) { top: 0px; }
    #nav-icon span:nth-child(2),
    #nav-icon span:nth-child(3) { top: 15px; }
    #nav-icon span:nth-child(4) { top: 30px; }
    #nav-icon.open span { background: rgb(181,181,181); background: linear-gradient(270deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%); }
    #nav-icon.open span:nth-child(1) { top: 15px; width: 0%; left: 50%; }
    #nav-icon.open span:nth-child(2) { -webkit-transform: rotate(45deg); -moz-transform: rotate(45deg); -o-transform: rotate(45deg); transform: rotate(45deg); }
    #nav-icon.open span:nth-child(3) { -webkit-transform: rotate(-45deg); -moz-transform: rotate(-45deg); -o-transform: rotate(-45deg); transform: rotate(-45deg); }
    #nav-icon.open span:nth-child(4) { top: 18px; width: 0%; left: 50%; }
    nav.navbar ul li a.nav-link {padding: 10px 20px;}
    nav.navbar.mobile ul.dropdown-menu.depth_0  {display: none; }
    .dropdown-toggle::after { display: none; }
    nav.navbar ul li a.nav-link + span { display: none;}
    nav.navbar ul li.menu-item-has-children span { display: inline-block !important; width: 50px; text-align: center; top: 0; right: 0; line-height: 1.6; z-index: 999; position: absolute; height: 100%; margin-left: .255em; border-width: 0; vertical-align: -0.745em; content: "+"; font-size: 24px; color: #fff; cursor: pointer; background: transparent; margin: 0; border: 0; }
    nav.navbar ul li.menu-item-has-children ul li span { display: none !important;}
    nav.navbar ul li a.nav-link { padding: 10px 20px; border-width: 0; display: inline-block; width: 100%;}
    nav.navbar ul li a.nav-link:hover { background: transparent; }
    nav.navbar ul li { border-bottom: 1px solid rgba(51, 51, 51, 0.86);}
    nav.navbar ul.dropdown-menu.depth_0.show  {display: inline-block; }
    .menu-item-has-children:after:hover: {background: #292929;}
    nav.navbar ul li span.show svg { transform: rotate(90deg);}
    nav.navbar ul.dropdown-menu.depth_0  { position: static; float: none; top: 0; width: 281px; border-radius: 0; padding: 0; margin: 0;border: none;}
    nav.navbar ul.dropdown-menu.depth_0 > li  { display: block;  margin: 0; padding: 0; border-width: 0; position: relative;}
    nav.navbar ul.dropdown-menu.depth_0 > li > span { display: none; }
    nav.navbar ul.dropdown-menu.depth_0 > li.dropdown > span { display: block; }
    nav.navbar ul.dropdown-menu.depth_0 > li > a { display: block; background: rgb(115,14,28); background: linear-gradient(270deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); margin: 0; padding: 10px 10px 10px 20px; }
    nav.navbar ul.dropdown-menu.depth_0 > li > ul  { display: none; border-width: 0;}
    nav.navbar ul.dropdown-menu.depth_1 { border:none; }
    nav.navbar ul.dropdown-menu.depth_1 > li > span  { display: none; }
    nav.navbar ul.dropdown-menu.depth_1  { position: static; float: none; background: #333; top: 0; width: 100%; border-radius: 0; padding: 0; margin: 0;}
    nav.navbar ul.dropdown-menu.depth_1 > li  { display: block; background: #333; margin: 0; padding: 0; border-width: 0; position: relative;}
    nav.navbar ul.dropdown-menu.depth_1 > li > a { display: block; background: #222; margin: 0; padding: 10px 10px 10px 20px; font-size: .875rem;}
    nav.navbar ul.dropdown-menu.depth_1 > li > ul  { display: none; background: #333; border-width: 0;}
    nav.navbar ul li span::after { content: "+"}
    nav.navbar ul li span.show::after { content: "-"}
    body {padding-top:91px}
    header { height: 91px; position: fixed; top: 0; transition: top 0.3s ease-in-out; width: 100%; z-index: 999}
    .nav-up { top: -91px;  }
    #wpadminbar {display: none;}
  }
  <?php $website_notification = get_field('website_notification' , 'options'); ?>
  @media only screen and ( min-width : 1201px ) {
    body { padding-top: 125px }
    body > header {<?php if ($website_notification): ?>height: 200px;<?php else: ?>height: 125px;<?php endif; ?> position: fixed; top: 0; transition: top 0.3s ease-in-out; width: 100%; z-index: 999}
    body > header.nav-up { top: -125px !important;  }
    body, body.admin-bar {<?php if ($website_notification): ?>padding-top: 20px;<?php else: ?>padding-top: 125px;<?php endif; ?>}
    body.admin-bar header { top: 32px; }
    #nav-icon {display: none;}
    nav.navbar {background: #f8f9fa; padding: 0; font-family: 'Rajdhani'; font-size: 1.25rem; font-weight: 600}
    nav.navbar .logo {width: 470px; text-align: center;position: relative;background: rgb(0,0,0); background: linear-gradient(270deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%);}
    /*.home nav.navbar .logo:after {position: absolute;content: ''; width: 260px; height: 4px; background: #DE2337; top: 123px; left:auto; }*/
    nav.navbar #navbarDropdown {flex: 1}
    nav.navbar .logo,
    nav.navbar #navbarDropdown {height: 125px;}
    nav.navbar ul { border-radius: 0; width: 100%;}
    nav.navbar ul.navbar-nav { -ms-flex-direction: row!important; flex-direction: row!important; display: -ms-flexbox!important; display: flex!important;}
    nav.navbar ul.navbar-nav > li {margin: 0 15px; padding: 45px 0;align-self: center;}
    nav.navbar ul.navbar-nav li a.nav-link {padding: 0; color: #fff;}
    nav.navbar ul.navbar-nav li { position: relative;}
    nav.navbar ul.navbar-nav > li:nth-of-type(1) { margin-left: 30px; }
    nav.navbar ul.navbar-nav li.menu-feature-btn { margin-left: auto; padding: 0;display: -ms-flexbox; display: flex; -ms-flex-align: center; align-items: center; }
    nav.navbar ul.navbar-nav li.menu-feature-btn a { width: 220px; border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 5px; position: relative; z-index: 1}
    nav.navbar ul.navbar-nav li.hidden { display: none; }
    nav.navbar ul.navbar-nav li.menu-feature-btn.red-btn a { background: #E51C38; /*background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%)*/}
    /*nav.navbar ul.navbar-nav li.menu-feature-btn.red-btn a::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); opacity: 0; transition: opacity 0.4s; z-index: -1; border-radius: 4px; }*/
    nav.navbar ul.navbar-nav li.menu-feature-btn.white-btn a { background: rgb(181,181,181); background: linear-gradient(180deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 100%); margin-right: 105px;color:#000;}
    nav.navbar ul.navbar-nav li.menu-feature-btn.white-btn a::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgb(181,181,181); background: linear-gradient(180deg, rgba(161,161,161,1) 0%, rgba(229,229,229,1) 150%); opacity: 0; transition: opacity 0.4s; z-index: -1; border-radius: 4px; }
    nav.navbar ul.navbar-nav li.menu-feature-btn:hover a::before { opacity: 1; }
    nav.navbar ul.navbar-nav li:nth-last-of-type(1) {margin: 0;}
    nav.navbar ul.navbar-nav li a + span { display: none;}
    nav.navbar ul.navbar-nav .dropdown-toggle::after { display: none;}
    nav.navbar ul.dropdown-menu.depth_0  {display: none;border: 0px solid transparent; background: transparent; width: 260px; position: absolute; float: unset; padding: 0;}
    nav.navbar ul.dropdown-menu.depth_0.show  {display: block;top: 100px !important; left: -30px; padding-top: 20px;}
    nav.navbar ul.dropdown-menu.depth_0 > li  {display: block;background: transparent; padding: 0; margin: 0;border-width: 0px;}
    nav.navbar ul.dropdown-menu.depth_0 > li > a {display: block; border: 0px solid transparent;color: #fff; padding: 15px 10px 15px 30px;margin: 0; border-radius: 0; font-size: 1.125rem !important; }
    nav.navbar ul.dropdown-menu.depth_0 > li > a { background: rgb(0,0,0); /*transition: ease all .3s;*/}
    nav.navbar ul.dropdown-menu.depth_0 > li:nth-last-of-type(1) > a {border-bottom: 4px solid #DE2337;}
    nav.navbar ul.dropdown-menu.depth_0 > li > a:hover { background: #c52131}
    nav.navbar ul.navbar-nav li ul.depth_0 li span { position: absolute; display: none; right: 20px; top: 16px;}
    nav.navbar ul.navbar-nav li ul.depth_0 li.menu-item-has-children span { display: inline-block;}
    nav.navbar ul.dropdown-menu.depth_1 {display: none; position: absolute; width: 100%; float: unset; right: -260px; top: -2px; left: auto; padding: 0}
    nav.navbar ul li ul.dropdown-menu.depth_1 li a {padding: 10px 10px 10px 20px; font-size: .875rem;}
    nav.navbar ul li ul.dropdown-menu.depth_1 li span {display: none !important;}
    nav.navbar ul li ul.dropdown-menu.depth_1.show {display: block;}
    .scscpq_search { top: -3px }
    .scscpq_search #scscpq_search_text { border-radius: 4px; box-sizing: border-box; border: 0px; padding: 3px 10px 3px 15px; }
  }
  @media only screen and ( min-width : 1200px ) and ( max-width : 1500px ){
    .home nav.navbar .logo:after {width: 60%; }
    .home nav.navbar .logo {width: 25%; padding: 0 1%;}
    nav.navbar #navbarDropdown {width: 75%; }
    nav.navbar {font-size: 1.125rem; }
    nav.navbar ul.navbar-nav > li {margin: 0 8px; padding: 46px 0; }
    nav.navbar ul.navbar-nav li.menu-feature-btn a { width: auto;}
    nav.navbar ul.navbar-nav li.menu-feature-btn a { padding: 10px 20px}
  }
  /* Gradients */
  .black-linear-horz-gradient { background: rgb(0,0,0); background: linear-gradient(270deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%); }
  .black-linear-horz-gradient-reverse { background: rgb(0,0,0); background: linear-gradient(90deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%); }
  .black-linear-vert-gradient { background: rgb(0,0,0); background: linear-gradient(180deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%); }
  .black-linear-vert-gradient-reverse { background: rgb(0,0,0); background: linear-gradient(0deg, rgba(0,0,0,1) 0%, rgba(61,61,61,1) 150%); }
  .red-linear-horz-gradient-reverse { background: rgb(115,14,28); background: linear-gradient(90deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); }
  .red-linear-horz-gradient { background: rgb(115,14,28); background: linear-gradient(270deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); }
  .red-linear-vert-gradient { background: rgb(115,14,28); background: linear-gradient(180deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); }
  .red-linear-vert-gradient-reverse { background: rgb(115,14,28); background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); }
  .white-linear-horz-gradient-reverse { background: rgb(181,181,181); background: linear-gradient(90deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%);}
  .white-linear-horz-gradient { background: rgb(181,181,181); background: linear-gradient(270deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%);}
  .white-linear-vert-gradient { background: rgb(181,181,181); background: linear-gradient(180deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%); }
  .white-linear-vert-gradient-reverse { background: rgb(181,181,181); background: linear-gradient(0deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%); }
  .black-radial-gradient { background: rgb(0,0,0); background: radial-gradient(circle, rgba(46,46,46,1) 0%, rgba(0,0,0,1) 150%); }
  .red-radial-gradient { background: rgb(115,14,28); background: radial-gradient(circle, rgba(229,28,56,1) 0%, rgba(115,14,28,1) 150%); }
  .red-radial-gradient-reverse { background: rgb(115,14,28); background: radial-gradient(circle, rgba(115,14,28,1)  0%, rgba(229,28,56,1) 150%); }
  .white-radial-gradient { background: rgb(181,181,181); background: radial-gradient(circle,  rgba(229,229,229,1) 0%, rgba(181,181,181,1) 150%); }
  .silver-radial-gradient { background: rgb(130,130,130); background: radial-gradient(circle, rgba(143,143,143,1)  0%, rgba(78,78,78,1) 100%); }
  .black-radial-gradient,
  .black-radial-gradient p,
  .red-radial-gradient p,
  .red-radial-gradient { color: #fff; }
  /* Buttons */
  .gform_button,
  .standard-btn { width: 220px; border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 5px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem; font-weight: 600; border: none; }
  .gform_button {width: 220px !important; font-size: 1.25rem !important; }
  .gform_button,
  .standard-btn.red {background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff;}
  .standard-btn.large { width: 260px;}
  .standard-btn.btn-small { width: 90px; padding: 3px 0px; color: #FFF; margin: 0; cursor: pointer; }
  .standard-btn.white { color:#000 }
  .standard-btn.white { background: rgb(181,181,181); background: linear-gradient(0deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%); color:#000 }
  .standard-btn.red::before {
    content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); opacity: 0; transition: opacity 0.4s; z-index: -1; border-radius: 4px;
  }
  .standard-btn.white::before {
    content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 0.4s; z-index: -1; border-radius: 4px;background: rgb(181,181,181); background: linear-gradient(180deg, rgba(181,181,181,1) 0%, rgba(229,229,229,1) 150%);
  }
  .standard-btn:hover::before { opacity: 1; }
  .job_listings .standard-btn.red { margin-top: 0; cursor: pointer;}

  button.submit { width: 220px !important; border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 5px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem !important; font-weight: 600; border: none; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff; cursor: pointer}
  button.submit::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%); opacity: 0; transition: opacity 0.4s; z-index: -1; border-radius: 4px; }
  button.submit:hover::before { opacity: 1; }

  .button-group .standard-btn:nth-child(2) { margin-left: 15px;}
  p + .standard-btn { margin-top: 30px;}
  p + .button-group .standard-btn { margin-top: 30px;}
  @media only screen and ( max-width : 1400px ){
    .gform_button,
    .standard-btn { width: 100% !important; font-size: 1rem; padding: 15px 20px;}
  }
  @media only screen and ( max-width : 400px ){
    .button-group .standard-btn,
    .gform_button,
    .standard-btn { width: 100%; display: block; padding: 15px 20px;}
    .button-group .standard-btn { margin: 0; }
    .button-group .standard-btn:nth-child(2) { margin-left: 0; margin-top: 15px}
  }
  .p-left { padding-left: 4%;}
  .has-overlay { position: relative; z-index: 1;}
  .overlay:after { position: absolute; content: ''; left: 0; top: 0; width: 100%; height: 100%;}
  span.red-headline {font-weight: 700; font-size: 1.0625rem; letter-spacing: 0.6px; color: #DE2337; text-transform: uppercase; opacity: 1; margin-bottom: .825rem; display: block;}
  span.white-headline {font-weight: 700; font-size: 1.0625rem; letter-spacing: 0.6px; color: #fff; text-transform: uppercase; opacity: 1; margin-bottom: .825rem; display: block;}
  .has-bg-img {background-size: cover; background-repeat: no-repeat; background-position: center;}
  .inline-btn { font-family: 'Rajdhani'; font-weight: 500; color:#fff; font-size: 1.125rem;}
  .inline-btn:before { content: '// '; color:#DE2337; font-weight: 700;}
  .list ul { padding: 0; font-weight: 600; font-size: 1.25rem;}
  .list li { list-style-type: none; line-height: 1.5}
  .list li:before { content: '// '; color:#DE2337; font-weight: 700; font-family: 'Rajdhani';margin-right: 5px;}
  @media only screen and ( max-width : 768px ) {
    .list ul { font-size: 1rem;}
    span.red-headline,
    span.white-headline {font-size: .925rem;}
  }
  @media only screen and ( max-width : 400px ) {
    .list ul { font-size: .875rem;}
  }
  /* Breadcrumbs */
  .breadcrumb-section {padding: 20px 0; background: transparent; color: #fff; font-family: 'Rajdhani';}
  #breadcrumbs { margin: 0; padding: 0; line-height: 1;}
  #breadcrumbs span { color: #B5B5B5 }
  #breadcrumbs span.breadcrumb_last {color:#d82935}
  #breadcrumbs a {color: #161616;}
  @media only screen and ( min-width : 1740px ) {
    .container.large { max-width: 1700px;}
  }
  /* Home Page Hero */
  .home-hero {background-size: cover;}
  .home-hero .background-overlay {background: rgba(255,255,255,.95); padding: 60px 0;}
  @media only screen and ( max-width : 1200px ) {
    .home-hero .background-overlay {padding: 60px 0;}
    body {padding-top:91px}
    body.admin-bar {padding-top:calc(91px - 32px)}
  }
  @media only screen and ( max-width : 991px ) {
    .home-hero .background-overlay {padding: 60px 0;}
    .home-hero .background-overlay img {margin: 60px auto 0; display: block;}
  }
  @media only screen and ( max-width : 768px ) {
    body {padding-top:77px}
    body.admin-bar {padding-top:calc(77px - 32px)}
    .home-hero .background-overlay {padding: 40px 0;}
    .home-hero .background-overlay img {display: none;}
  }
  figure.wp-block-image{ padding: 40px; text-align: center; margin: 40px auto !important; border-top: 4px solid #de242d; border-bottom: 4px solid #de242d; background: #212121}
  figure.wp-block-image img { width: 100%: }
  </style>
  <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
	<script>!function () {var jeeva = window.jeeva = window.jeeva || [];if (jeeva.invoked) return;jeeva.invoked = true;jeeva.methods = ['identify', 'collect'];jeeva.factory = function (method) {return function () {var args = Array.prototype.slice.call(arguments);args.unshift(method);jeeva.push(args);return jeeva;};};for (var i = 0; i < jeeva.methods.length; i++) {var key = jeeva.methods[i];jeeva[key] = jeeva.factory(key);}jeeva.load = function (key) {var script = document.createElement('script');script.type = 'text/javascript';script.async = true;script.src = 'https://r2d2-inbound-js-store-production.s3.us-east-1.amazonaws.com/' + key + '/jeeva.js';var first = document.getElementsByTagName('script')[0];first.parentNode.insertBefore(script, first);};jeeva.SNIPPET_VERSION = '1.0.';jeeva.load('e29cd84e-22a4-4823-97ad-e2904e78af60');}();</script>
</head>
<body <?php body_class(); ?>>

  <header class="nav-down">
	  
	  <!-- <div class="language-switcher">
		  <?php do_action('wpml_add_language_selector'); ?>
	  </div> -->

    <nav class="navbar flex-nowrap">

      <div class="logo d-flex align-items-center justify-content-center">
        <?php the_custom_logo(); ?>
      </div>

      <div class="black-linear-horz-gradient d-flex align-items-center flex-1 " id="navbarDropdown">
        <?php
        wp_nav_menu( array(
          'theme_location'  => 'navbar',
          'container'       => false,
          'menu_class'      => 'cbp-spmenu cbp-spmenu-vertical cbp-spmenu-right d-flex',
          'fallback_cb'     => '__return_false',
          'items_wrap'      => '<ul id="%1$s" class="navbar-nav ml-auto my-0 mr-0 %2$s">%3$s</ul>',
          'depth'           => 3,
          'walker'          => new momentumst_walker_nav_menu()
        ) );
        ?>


      </div>

      <div class="menu-right push-body" id="nav-icon">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
      </div>

    </nav>


<?php if ($website_notification): ?>
  <div class="legacy-website p-3"><p class="m-0 small"><?php echo $website_notification ?></p></div>
<?php endif; ?>

  </header>
  <div class="mobile-overlay"></div>
