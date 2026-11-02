(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mahlzeitNavigation = {
    attach: function (context) {
      once('mahlzeit-navigation', '[data-menu-toggle]', context).forEach(function (toggle) {
        var nav = document.querySelector('[data-mobile-nav]');
        if (!nav) return;
        toggle.addEventListener('click', function () {
          var isOpen = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!isOpen));
          nav.hidden = isOpen;
          toggle.querySelector('.visually-hidden').textContent = isOpen ? Drupal.t('Open navigation') : Drupal.t('Close navigation');
        });
      });
      once('mahlzeit-header-scroll', '[data-site-header]', context).forEach(function (header) {
        var update = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', update, { passive: true });
        update();
      });
    }
  };

  Drupal.behaviors.mahlzeitScrollReveal = {
    attach: function (context) {
      var selector = [
        '.osd-home-hero__copy',
        '.osd-hero-visual',
        '.osd-proof-grid > div',
        '.home-vocabulary-card',
        '.osd-section-heading',
        '.osd-part-card',
        '.osd-level-card',
        '.osd-plan-card',
        '.osd-simulation-copy',
        '.osd-report-card',
        '.osd-content-card',
        '.osd-final-cta__inner'
      ].join(',');
      var items = once('mahlzeit-scroll-reveal', selector, context);
      if (!items.length) return;

      document.documentElement.classList.add('has-scroll-reveal');

      var staggerGroups = [
        ['.osd-proof-grid > div', 70],
        ['.osd-parts-grid > .osd-part-card', 80],
        ['.osd-level-grid > .osd-level-card', 70],
        ['.osd-plans-grid > .osd-plan-card', 80],
        ['.osd-content-grid > .osd-content-card', 70]
      ];
      staggerGroups.forEach(function (group) {
        Array.prototype.forEach.call(context.querySelectorAll(group[0]), function (item, index) {
          item.style.setProperty('--scroll-reveal-delay', Math.min(index * group[1], 280) + 'ms');
        });
      });

      var reveal = function (item) {
        item.classList.add('is-visible');
      };

      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
        Array.prototype.forEach.call(items, reveal);
        return;
      }

      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          reveal(entry.target);
          observer.unobserve(entry.target);
        });
      }, {
        threshold: 0.12,
        rootMargin: '0px 0px -8% 0px'
      });

      Array.prototype.forEach.call(items, function (item) {
        observer.observe(item);
      });
    }
  };
})(Drupal, once);
