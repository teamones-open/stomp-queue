FROM rabbitmq:3.13-management

ARG PLUGIN_VERSION=3.13.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && curl -fsSL -o /opt/rabbitmq/plugins/rabbitmq_delayed_message_exchange-${PLUGIN_VERSION}.ez \
        https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v${PLUGIN_VERSION}/rabbitmq_delayed_message_exchange-${PLUGIN_VERSION}.ez \
    && rabbitmq-plugins enable --offline rabbitmq_stomp rabbitmq_delayed_message_exchange \
    && rm -rf /var/lib/apt/lists/*
