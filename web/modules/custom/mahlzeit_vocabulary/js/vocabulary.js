(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mahlzeitVocabulary = {
    attach: function (context) {
      once('mahlzeit-vocabulary', '[data-vocabulary-app]', context).forEach(function (app) {
        var cards = Array.prototype.slice.call(app.querySelectorAll('[data-vocabulary-card]'));
        var flashcards = app.querySelector('[data-vocabulary-flashcards]');
        var list = app.querySelector('[data-vocabulary-list]');
        var viewButtons = Array.prototype.slice.call(app.querySelectorAll('[data-vocabulary-view]'));
        var previous = app.querySelector('[data-vocabulary-prev]');
        var next = app.querySelector('[data-vocabulary-next]');
        var currentLabel = app.querySelector('[data-vocabulary-current]');
        var progress = app.querySelector('[data-vocabulary-progress]');
        var index = 0;
        var revealed = false;
        var view = 'flashcards';

        function render() {
          cards.forEach(function (card, cardIndex) {
            var isCurrent = cardIndex === index;
            card.hidden = !isCurrent;
            card.classList.toggle('is-current', isCurrent);
            card.classList.toggle('is-revealed', isCurrent && revealed);
            card.setAttribute('aria-expanded', isCurrent && revealed ? 'true' : 'false');
            var back = card.querySelector('.vocabulary-flashcard__back');
            if (back) {
              back.setAttribute('aria-hidden', isCurrent && !revealed ? 'true' : 'false');
            }
          });

          flashcards.hidden = view !== 'flashcards';
          list.hidden = view !== 'list';
          viewButtons.forEach(function (button) {
            var active = button.getAttribute('data-vocabulary-view') === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
          });

          if (currentLabel) {
            currentLabel.textContent = String(index + 1);
          }
          if (progress) {
            progress.style.width = ((index + 1) / cards.length * 100) + '%';
          }
          if (previous) {
            previous.disabled = cards.length < 2;
          }
          if (next) {
            next.disabled = cards.length < 2;
          }
        }

        function move(step) {
          index = (index + step + cards.length) % cards.length;
          revealed = false;
          render();
        }

        function toggleCard() {
          revealed = !revealed;
          render();
        }

        viewButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            view = button.getAttribute('data-vocabulary-view') || 'flashcards';
            render();
          });
        });

        cards.forEach(function (card) {
          card.addEventListener('click', toggleCard);
          card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              toggleCard();
            }
          });
        });
        if (previous) {
          previous.addEventListener('click', function () { move(-1); });
        }
        if (next) {
          next.addEventListener('click', function () { move(1); });
        }

        render();
      });
    }
  };
})(Drupal, once);
