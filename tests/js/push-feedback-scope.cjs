/* item 36: the push-feedback.js exports the EzSheetPreview template needs (EZ_PUSH_TEXTS, ezPushRefusalText),
   loaded without a bundler for the tests that evaluate that template. */
const fs = require('fs'), path = require('path'), vm = require('vm');
const src = fs.readFileSync(path.join(__dirname, '../../resources/js/components/push-feedback.js'), 'utf8').replace(/^export /gm, '');
const ctx = {};
vm.runInNewContext(src + '\n;this.EZ_PUSH_TEXTS = EZ_PUSH_TEXTS; this.ezPushRefusalText = ezPushRefusalText; this.ezPushFeedback = ezPushFeedback;', ctx);
module.exports = ctx;
