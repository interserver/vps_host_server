.PHONY: all build build-v2 clean help summary run-tui dockerfiles list status

# Default parallelism
PARALLELISM ?= 6

all: help

help:
	@echo ""
	@echo "Docker SSH Image Builder v2"
	@echo "============================"
	@echo ""
	@echo "Available targets:"
	@echo ""
	@echo "  make build        - Run v1 builder (basic)"
	@echo "  make build-v2    - Run v2 builder with TUI dashboard (RECOMMENDED)"
	@echo "  make run-tui      - Same as build-v2"
	@echo "  make summary      - Show current build summary"
	@echo "  make clean        - Clean all logs, dockerfiles, and built images"
	@echo "  make clean-images - Clean only Docker images"
	@echo "  make dockerfiles  - Generate all Dockerfiles without building"
	@echo "  make list         - List all generated Dockerfiles"
	@echo "  make status       - Show current build status"
	@echo ""
	@echo "Environment variables:"
	@echo "  PARALLELISM=N     - Number of parallel builds (default: 6)"
	@echo "  SSH_PASSWORD=pwd  - SSH password for containers (default: InterServer!23)"
	@echo ""
	@echo "Examples:"
	@echo ""
	@echo "  make build-v2 P=8              # Build with 8 parallel processes"
	@echo "  PARALLELISM=4 make build-v2     # Same as above"
	@echo "  SSH_PASSWORD=secret make build-v2 # Custom SSH password"
	@echo ""

build:
	@echo "Starting v1 builder..."
	@chmod +x build_images_enhanced.sh
	PARALLELISM=$(PARALLELISM) ./build_images_enhanced.sh

build-v2 run-tui:
	@echo "Starting v2 builder with TUI dashboard..."
	@chmod +x docker_ssh_builder_v2.sh
	PARALLELISM=$(PARALLELISM) ./docker_ssh_builder_v2.sh

summary:
	@chmod +x summary.sh
	./summary.sh

clean:
	@echo "Cleaning up..."
	@rm -rf logs/ dockerfiles/ output/
	@echo "Directories cleaned."

clean-images:
	@echo "Removing Docker images..."
	@docker images | grep '^ssh-' | awk '{print $$3}' | xargs -r docker rmi -f 2>/dev/null || true
	@echo "Images cleaned."

dockerfiles:
	@echo "Generating Dockerfiles only (no build)..."
	@chmod +x generate_dockerfiles.sh
	./generate_dockerfiles.sh

list:
	@echo "Generated Dockerfiles:"
	@ls -la dockerfiles/ 2>/dev/null || echo "No dockerfiles directory found"

logs:
	@echo "Build logs:"
	@tail -100 logs/build.log

status:
	@echo "Current status:"
	@if command -v jq &>/dev/null && [ -f output/results.json ]; then \
		jq '.results.statistics' output/results.json 2>/dev/null || echo "No results yet"; \
	else \
		echo "Good templates: $$(wc -l < output/good_templates.txt 2>/dev/null || echo 0)"; \
		echo "Bad templates:  $$(wc -l < output/bad_templates.txt 2>/dev/null || echo 0)"; \
	fi
	@echo ""
	@echo "Recent activity:"
	@tail -5 logs/build.log

json-results:
	@if command -v jq &>/dev/null && [ -f output/results.json ]; then \
		jq '.' output/results.json; \
	else \
		echo "Install jq for JSON output: apt install jq"; \
		cat output/results.json 2>/dev/null || echo "No results yet"; \
	fi

tags-cache:
	@echo "Tags cache:"
	@cat output/tags_cache.json 2>/dev/null || echo "No cache found"
