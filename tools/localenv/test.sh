#!/bin/sh
# Lance les tests du plugin dans le WordPress local.
#
#   YUME_WP_PATH=… YUME_ENV=tests tools/localenv/test.sh            # tous les modules
#   YUME_WP_PATH=… YUME_ENV=tests tools/localenv/test.sh planning   # tests/test-planning.php seulement
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
"$DIR/wp.sh" eval-file wp-content/plugins/yume-core/tests/runner.php "$@"
