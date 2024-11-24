class Client {
    static onMessage = null;
    static onConnect = null;
    static onClose = null;

    static connection = null;
    static socketName = null;
    static reconnectTimer = null;
    static pingTimer = null;
    static events = {};
    static queues = [];
    static pingInterval = 25;

    static connect(socketName = null) {
        if (this.connection) {
            return;
        }

        this.socketName = socketName;
        this.connection = new WebSocket(this.socketName);

        this.connection.onopen = () => {
            this.onConnectHandler();
        };

        this.connection.onmessage = (message) => {
            this.onMessageHandler(message);
        };

        this.connection.onclose = () => {
            this.onCloseHandler();
        };

        if (!this.pingTimer) {
            this.pingTimer = setInterval(() => this.ping(), this.pingInterval * 1000);
        }
    }

    static onMessageHandler(message) {
        const data = JSON.parse(message.data);
        const type = data.type;
        const event = data.channel;
        const eventData = data.data;

        if (type === 'event') {
            if (this.events[event]) {
                this.events[event](eventData);
            } else if (this.onMessage) {
                this.onMessage(event, eventData);
            } else {
                throw new Error(`Event ${event} is not a function`);
            }
        } else {
            if (this.queues[event]) {
                this.queues[event](eventData);
            } else {
                throw new Error(`Queue ${event} is not a function`);
            }
        }
    }

    static onCloseHandler() {
        console.warn("Соединение закрыто, попытка переподключения");
        this.connection = null;
        this.clearTimer();
        this.reconnectTimer = setTimeout(() => this.connect(this.socketName), 1000);
        if (this.onClose) {
            this.onClose();
        }
    }

    static onConnectHandler() {
        const allEventNames = Object.keys(this.events);
        if (allEventNames.length) {
            this.subscribe(allEventNames);
        }
        this.clearTimer();

        if (this.onConnect) {
            this.onConnect();
        }
    }

    static ping() {
        if (this.connection && this.connection.readyState === WebSocket.OPEN) {
            this.connection.send('');
        }
    }

    static clearTimer() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    static on(event, callback) {
        if (typeof callback !== 'function') {
            throw new Error('Callback не поддается вызову для события.');
        }
        this.events[event] = callback;
        this.subscribe(event);
    }

    static subscribe(events) {
        events = Array.isArray(events) ? events : [events];
        this.send({
            type: 'subscribe',
            channels: events
        });
        events.forEach(event => {
            if (!this.events[event]) {
                this.events[event] = null;
            }
        });
    }

    static unsubscribe(events) {
        events = Array.isArray(events) ? events : [events];
        this.send({
            type: 'unsubscribe',
            channels: events
        });
        events.forEach(event => {
            delete this.events[event];
        });
    }

    static publish(events, data, isLoop = false) {
        this.send({
            type: isLoop ? 'publishLoop' : 'publish',
            channels: Array.isArray(events) ? events : [events],
            data
        });
    }

    static watch(channels, callback, autoReserve = true) {
        if (typeof callback !== 'function') {
            throw new Error('Callback не поддается вызову для наблюдения.');
        }

        const wrappedCallback = (data) => {
            try {
                callback(data);
            } finally {
                if (autoReserve) {
                    this.reserve();
                }
            }
        };

        this.send({
            type: 'watch',
            channels: Array.isArray(channels) ? channels : [channels]
        });

        (Array.isArray(channels) ? channels : [channels]).forEach(channel => {
            this.queues[channel] = wrappedCallback;
        });

        if (autoReserve) {
            this.reserve();
        }
    }

    static unwatch(channels) {
        this.send({
            type: 'unwatch',
            channels: Array.isArray(channels) ? channels : [channels]
        });
        (Array.isArray(channels) ? channels : [channels]).forEach(channel => {
            delete this.queues[channel];
        });
    }

    static enqueue(channels, data) {
        this.send({
            type: 'enqueue',
            channels: Array.isArray(channels) ? channels : [channels],
            data
        });
    }

    static reserve() {
        this.send({
            type: 'reserve'
        });
    }

    static send(data) {
        if (!this.connection || this.connection.readyState !== WebSocket.OPEN) {
            this.connect(this.socketName);
        }
        this.connection.send(JSON.stringify(data));
    }
}
