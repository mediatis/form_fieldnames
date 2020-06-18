define([], function () {
    'use strict';

    return (function () {

        /**
         * @private
         *
         * @var object
         */
        var _formEditorApp = null;

        /**
         * @private
         *
         * @return object
         */
        function getFormEditorApp() {
            return _formEditorApp;
        }

        function _addPropertyValidators() {
            getFormEditorApp().addPropertyValidationValidator('Unique', function(formElement, propertyPath) {
                var id = formElement.get('identifier');
                var value = formElement.get(propertyPath);
                var foundElements = [];
                function checkUniqueness(iterator) {
                    var propertyId = iterator.get('identifier');
                    var propertyValue = iterator.get(propertyPath);
                    if (id !== propertyId && propertyValue && propertyValue === value) {
                        foundElements.push(propertyId);
                    }
                    var renderables = iterator.get('renderables');
                    if (renderables) {
                        for (var i = 0; i < renderables.length; i++) {
                            checkUniqueness(renderables[i]);
                        }
                    }
                }
                checkUniqueness(getFormEditorApp().getRootFormElement());
                if (foundElements.length > 0) {
                    return 'Must be unique (' + foundElements.join(', ') + ')';
                }
            });
        }

        /**
         * @public
         *
         * @param object formEditorApp
         * @return void
         */
        function bootstrap(formEditorApp) {
            _formEditorApp = formEditorApp;
            _addPropertyValidators();
        }

        /**
         * Publish the public methods.
         * Implements the "Revealing Module Pattern".
         */
        return {
            bootstrap: bootstrap
        };
    })();
});
