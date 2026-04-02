#!/bin/bash
# Binding Request Processor Control Script
# Usage: ./scripts/binding-processor.sh {start|stop|restart|status}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PROCESSOR_SCRIPT="$PROJECT_DIR/api/process-binding-requests.php"
PID_FILE="$PROJECT_DIR/logs/binding-processor.pid"
LOG_FILE="$PROJECT_DIR/logs/binding-processor.log"

# Ensure logs directory exists
mkdir -p "$PROJECT_DIR/logs"

start() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            echo "Binding processor is already running (PID: $PID)"
            return 1
        else
            echo "Removing stale PID file"
            rm -f "$PID_FILE"
        fi
    fi
    
    echo "Starting binding request processor..."
    nohup php "$PROCESSOR_SCRIPT" --daemon --interval=5 >> "$LOG_FILE" 2>&1 &
    PID=$!
    echo $PID > "$PID_FILE"
    echo "Binding processor started (PID: $PID)"
    echo "Log file: $LOG_FILE"
}

stop() {
    if [ ! -f "$PID_FILE" ]; then
        echo "Binding processor is not running (no PID file found)"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    
    if ! ps -p "$PID" > /dev/null 2>&1; then
        echo "Binding processor is not running (process not found)"
        rm -f "$PID_FILE"
        return 1
    fi
    
    echo "Stopping binding request processor (PID: $PID)..."
    kill "$PID"
    
    # Wait for process to stop (max 10 seconds)
    for i in {1..10}; do
        if ! ps -p "$PID" > /dev/null 2>&1; then
            echo "Binding processor stopped"
            rm -f "$PID_FILE"
            return 0
        fi
        sleep 1
    done
    
    # Force kill if still running
    echo "Process did not stop gracefully, forcing..."
    kill -9 "$PID"
    rm -f "$PID_FILE"
    echo "Binding processor forcefully stopped"
}

status() {
    if [ ! -f "$PID_FILE" ]; then
        echo "Binding processor is not running (no PID file)"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "Binding processor is running (PID: $PID)"
        echo "Log file: $LOG_FILE"
        return 0
    else
        echo "Binding processor is not running (stale PID file)"
        rm -f "$PID_FILE"
        return 1
    fi
}

restart() {
    echo "Restarting binding request processor..."
    stop
    sleep 2
    start
}

case "$1" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        restart
        ;;
    status)
        status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac

exit $?
