<?php

$list = get_field('hero_list');
$title = get_field('hero_title');
$description = get_field('hero_description');
$background_image = get_field('background_image');
$website_notification = get_field('website_notification' , 'options');

?>
<section id="signup" class="signup-hero <?php if($website_notification): echo "add-margin"; endif; ?>" style="background-image:url('<?php if (is_array($background_image)): echo $background_image['url']; elseif (is_numeric($background_image)): echo wp_get_attachment_url($background_image); elseif (!empty($background_image)): echo $background_image; endif; ?>')">
  <div class="background-overlay site-padding">
    <div class="container large">
      <div class="row align-items-start">
        <div data-aos-duration="1500" data-aos="fade-right" class="col-lg-6 pr-lg-4">
          <?php get_template_part('layouts/sections/global/breadcrumbs'); ?>
          <h1 class="mt-4"><?php echo $title ?> </h1>
          <div class="description mt-4">
            <?php echo $description ?>
          </div>
          <div class="list mt-4">
            <ul>

              <?php

              if( have_rows('hero_list') ):

                while ( have_rows('hero_list') ) : the_row();

                $item = get_sub_field('item');

                echo "<li>{$item}</li>";

              endwhile;

              else :

              endif;
              ?>
            </ul>
          </div>
        </div>
        <div class="col-lg-6">
          <div id="scscpq_page"><div class="noshow">newuser</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<style media="screen">
.signup-hero .background-overlay { background: rgba(255,255,255,.95)}
.signup-hero .parts-solution {background: #F3F3F3; padding-left: 20px; padding-right: 20px;}
.signup-hero .parts-solution iframe {border: none; padding: 5px;}
</style>
