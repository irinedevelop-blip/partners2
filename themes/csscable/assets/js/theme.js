document.addEventListener('DOMContentLoaded', () => {
	const body = document.body;
	const openButton = document.querySelector('[data-sidebar-open]');
	const closeButtons = document.querySelectorAll('[data-sidebar-close]');

	const openSidebar = () => {
		body.classList.add('cc-sidebar-is-open');
	};

	const closeSidebar = () => {
		body.classList.remove('cc-sidebar-is-open');
	};

	if (openButton) {
		openButton.addEventListener('click', openSidebar);
	}

	closeButtons.forEach((button) => {
		button.addEventListener('click', closeSidebar);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			closeSidebar();
		}
	});

	window.addEventListener('resize', () => {
		if (window.innerWidth > 960) {
			closeSidebar();
		}
	});
});
