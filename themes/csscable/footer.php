<?php
/**
 * Footer.
 *
 * @package CSSCable
 */

defined( 'ABSPATH' ) || exit;
?>
		</main>

		<footer class="cc-footer">
			<span>
				&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?>
				<?php bloginfo( 'name' ); ?>
			</span>

			<?php
			wp_nav_menu( array(
				'theme_location' => 'footer',
				'container'      => false,
				'menu_class'     => 'cc-footer__menu',
				'fallback_cb'    => false,
				'depth'          => 1,
			) );
			?>
		</footer>
	</div>
</div>

<?php wp_footer(); ?>
</body>
</html>
