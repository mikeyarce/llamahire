// Global setup exports this flag to every worker, including browser.newContext().
module.exports = async () => {
	process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
};
