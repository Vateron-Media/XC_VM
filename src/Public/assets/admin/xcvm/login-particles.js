/* Subtle tsParticles background for the classic admin login. */
(function () {
	"use strict";

	var container = document.getElementById("tsparticles");
	if (!container || !window.tsParticles || typeof window.loadSlim !== "function") {
		return;
	}

	var root = document.documentElement;
	var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	var particles = null;
	var reloadTimer = null;

	function darken(color) {
		var value = /^#[0-9a-f]{6}$/i.test(color) ? color.slice(1) : "696cff";
		return "#" + [0, 2, 4].map(function (index) {
			return Math.round(parseInt(value.slice(index, index + 2), 16) * 0.8).toString(16).padStart(2, "0");
		}).join("");
	}

	function loadParticles() {
		var dark = root.getAttribute("data-bs-theme") === "dark";
		var primary = getComputedStyle(root).getPropertyValue("--bs-primary").trim() || "#696cff";
		var accent = dark ? primary : darken(primary);
		var neutral = dark ? "#a8b0c2" : "#344054";
		if (particles) {
			particles.destroy();
			particles = null;
		}
		return window.tsParticles.load({
			id: "tsparticles",
			options: {
				fullScreen: { enable: false },
				fpsLimit: 45,
				detectRetina: true,
				pauseOnBlur: true,
				background: { color: { value: "transparent" } },
				particles: {
					paint: {
						color: { value: dark ? neutral : accent },
						fill: {
							enable: true,
							color: { value: dark ? [primary, neutral] : accent }
						}
					},
					links: {
						enable: true,
						color: accent,
						distance: 140,
						opacity: dark ? 0.16 : 0.52,
						width: dark ? 1 : 1.25
					},
					move: {
						enable: !reduceMotion,
						direction: "none",
						outModes: { default: "out" },
						random: true,
						speed: 0.35,
						straight: false
					},
					number: {
						density: { enable: true, width: 900, height: 900 },
						value: dark ? 54 : 60
					},
					opacity: { value: dark ? { min: 0.16, max: 0.36 } : { min: 0.58, max: 0.9 } },
					shape: { type: "circle" },
					size: { value: dark ? { min: 1, max: 2.6 } : { min: 1.4, max: 3.2 } }
				},
				interactivity: {
					detectsOn: "window",
					events: {
						onClick: { enable: false },
						onHover: { enable: !reduceMotion, mode: "grab" }
					},
					modes: {
						grab: {
							distance: 165,
							links: { opacity: dark ? 0.5 : 0.9 }
						}
					}
				}
			}
		}).then(function (loaded) {
			particles = loaded || window.tsParticles.item(0);
		});
	}

	window.loadSlim(window.tsParticles).then(function () {
		new MutationObserver(function () {
			clearTimeout(reloadTimer);
			reloadTimer = setTimeout(function () {
				loadParticles().catch(function () {});
			}, 50);
		}).observe(root, { attributes: true, attributeFilter: ["data-bs-theme", "style"] });
		return loadParticles();
	}).catch(function () {
		// Decorative background: the login remains usable if the CDN is unavailable.
	});
})();
