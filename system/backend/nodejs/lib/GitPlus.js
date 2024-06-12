const { Git } = require('git-interface');

class GitPlus extends Git {
  async revert(count) {
    let counter = 0;
    // sanity check
    if (count < 1) {
        count = 1;
    }
    while (counter != count) {
        await this.gitExec("reset --hard HEAD~1");
        counter++;
    }
    return true;
  }
}

module.exports = GitPlus;