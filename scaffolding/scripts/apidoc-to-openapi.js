#!/usr/bin/env node

/**
 * apiDoc to OpenAPI 3.0 Converter
 *
 * Converts PHP files with apiDoc (@api*) annotations to OpenAPI 3.0 JSON spec
 * and an interactive RapiDoc HTML viewer.
 *
 * Usage: node scripts/apidoc-to-openapi.js
 *
 * Config: src/Api/apidoc.json — all application-specific settings live there.
 * See scaffolding/templates/api-doc.json.stub for the full schema.
 */

const fs = require('fs');
const path = require('path');

// Paths only — application-specific values come from apidoc.json
const CONFIG = {
  sourceDir:      './src/Api/Controllers',
  outputFile:     './www/api/openapi.json',
  swaggerHtmlDir: './www/api/docs',
  apidocOldDir:   './www/api/docs/old',
  apidocConfig:   './src/Api/apidoc.json',
  customOverrides: './src/Api/openapi-overrides.json',
};

class ApiDocToOpenAPIConverter {
  constructor(config) {
    this.config = config;
    this.versions = new Set(); // Track all API versions found
    this.endpointsByVersion = {}; // Store endpoints grouped by version
    this.collectedSchemas = {}; // Collect schemas from all endpoints
  }

  initOpenAPISpec(version) {
    // Load apidoc.json for base information
    let apidocConfig = {};
    try {
      apidocConfig = JSON.parse(fs.readFileSync(this.config.apidocConfig, 'utf8'));
    } catch (e) {
      console.warn('Could not load apidoc.json, using defaults');
    }

    const baseUrl = (apidocConfig.url || 'https://api.example.com').replace(/\/$/, '');

    // Build servers list: production from apidoc.json + additional from config
    const servers = [
      {
        url: baseUrl + '/' + version,
        description: 'Production server'
      }
    ];

    // Add additional servers from apidoc.json config
    const additionalServers = apidocConfig.additionalServers || [];
    additionalServers.forEach(server => {
      servers.push({
        url: server.url + '/' + version,
        description: server.description
      });
    });

    return {
      openapi: '3.0.3',
      info: {
        title: apidocConfig.name || 'API Documentation',
        version: version,
        description: apidocConfig.description || 'API Documentation'
      },
      servers: servers,
      security: [
        {
          ApiKeyAuth: [],
          AccessTokenAuth: []
        }
      ],
      components: {
        securitySchemes: {
          ApiKeyAuth: {
            type: 'apiKey',
            in: 'header',
            name: 'apiKey'
          },
          AccessTokenAuth: {
            type: 'apiKey',
            in: 'header',
            name: 'accessToken'
          }
        },
        schemas: {},
        parameters: {
          PageParam: {
            name: 'page',
            in: 'query',
            description: 'Page number for pagination. Set to 0 to get all results',
            schema: { type: 'integer', default: 0 }
          },
          LimitParam: {
            name: 'limit',
            in: 'query',
            description: 'Limit number of results per page',
            schema: { type: 'integer', default: 20 }
          },
          SortParam: {
            name: 'sort',
            in: 'query',
            description: 'Sort by field. Syntax: [+-]fieldname,[+-]fieldname',
            schema: { type: 'string' }
          },
          SearchParam: {
            name: 'search',
            in: 'query',
            description: 'Global search term. Supports JSON format for specific fields',
            schema: { type: 'string' }
          },
          FieldsParam: {
            name: 'fields',
            in: 'query',
            description: 'Specify which fields to return (comma-separated or JSON array)',
            schema: { type: 'string' }
          }
        }
      },
      paths: {},
      tags: []
    };
  }

  parsePhpFile(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');
    const endpoints = [];

    // Extract all apiDoc blocks
    const apiDocRegex = /\/\*\*\s*([\s\S]*?)\*\//g;
    let match;

    while ((match = apiDocRegex.exec(content)) !== null) {
      const block = match[1];

      // Check if this is an API endpoint block
      if (block.includes('@api')) {
        const endpoint = this.parseApiDocBlock(block);
        if (endpoint) {
          endpoints.push(endpoint);
        }
      }
    }

    return endpoints;
  }

  parseApiDocBlock(block) {
    const lines = block.split('\n').map(line => line.trim().replace(/^\*\s?/, ''));

    const endpoint = {
      method: '',
      path: '',
      group: '',
      name: '',
      description: '',
      version: '',
      permission: '',
      deprecated: false,
      deprecatedMessage: '',
      headers: [],
      params: [],
      body: [],
      success: [],
      successExamples: [],
      currentExampleTitle: '',
      currentExampleContent: '',
      error: [],
      errorExample: ''
    };

    let currentSection = null;

    for (const line of lines) {
      // @api
      const apiMatch = line.match(/@api\s+\{(\w+)\}\s+([\S]+)(?:\s+(.+))?/);
      if (apiMatch) {
        endpoint.method = apiMatch[1].toLowerCase();
        endpoint.path = '/' + apiMatch[2];
        endpoint.summary = apiMatch[3] || '';
        continue;
      }

      // @apiVersion
      const versionMatch = line.match(/@apiVersion\s+(.+)/);
      if (versionMatch) {
        endpoint.version = versionMatch[1];
        continue;
      }

      // @apiSince - marks the version when this endpoint was introduced
      // Endpoints with @apiSince 1.1 will only appear in v1.1+, not in v1.0
      const sinceMatch = line.match(/@apiSince\s+(.+)/);
      if (sinceMatch) {
        endpoint.since = sinceMatch[1].trim();
        continue;
      }

      // @apiGroup
      const groupMatch = line.match(/@apiGroup\s+(.+)/);
      if (groupMatch) {
        endpoint.group = groupMatch[1];
        continue;
      }

      // @apiName
      const nameMatch = line.match(/@apiName\s+(.+)/);
      if (nameMatch) {
        endpoint.name = nameMatch[1];
        continue;
      }

      // @apiDescription
      const descMatch = line.match(/@apiDescription\s+(.+)/);
      if (descMatch) {
        endpoint.description = descMatch[1];
        currentSection = 'description';
        continue;
      }

      // @apiPermission
      const permMatch = line.match(/@apiPermission\s+(.+)/);
      if (permMatch) {
        endpoint.permission = permMatch[1];
        continue;
      }

      // @apiDeprecated
      const deprecatedMatch = line.match(/@apiDeprecated\s*(.*)/);
      if (deprecatedMatch) {
        endpoint.deprecated = true;
        endpoint.deprecatedMessage = deprecatedMatch[1] || 'This endpoint is deprecated';
        currentSection = 'deprecated';
        continue;
      }

      // @apiHeader
      const headerMatch = line.match(/@apiHeader\s+\{([^}]+)\}\s+(\S+)(?:\s+(.+))?/);
      if (headerMatch) {
        endpoint.headers.push({
          type: headerMatch[1],
          name: headerMatch[2],
          description: headerMatch[3] || ''
        });
        currentSection = null;
        continue;
      }

      // @apiParam (query parameters)
      const paramMatch = line.match(/@apiParam\s+\{([^}]+)\}\s+(\[)?([^\]=\s]+)(=([^\]]+))?(\])?(?:\s+(.+))?/);
      if (paramMatch) {
        const isOptional = !!paramMatch[2]; // Has opening bracket
        const fieldName = paramMatch[3];
        const defaultValue = paramMatch[5];
        const description = paramMatch[7] || '';

        endpoint.params.push({
          type: paramMatch[1],
          name: fieldName,
          defaultValue: defaultValue || undefined,
          optional: isOptional,
          description: description
        });
        currentSection = 'param';
        continue;
      }

      // @apiBody (request body parameters)
      const bodyMatch = line.match(/@apiBody\s+\{([^}]+)\}\s+(\[)?([^\]=\s]+)(=([^\]]+))?(\])?(?:\s+(.+))?/);
      if (bodyMatch) {
        const isOptional = !!bodyMatch[2]; // Has opening bracket
        const fieldName = bodyMatch[3];
        const defaultValue = bodyMatch[5];
        const description = bodyMatch[7] || '';

        endpoint.body.push({
          type: bodyMatch[1],
          name: fieldName,
          defaultValue: defaultValue || undefined,
          optional: isOptional,
          description: description
        });
        currentSection = 'body';
        continue;
      }

      // @apiSuccessExample - MUST be checked before @apiSuccess (since it contains @apiSuccess)
      // Format: @apiSuccessExample {json} Title:
      const successExampleMatch = line.match(/@apiSuccessExample\s*(?:\{[^}]+\})?\s*(.+)?/);
      if (successExampleMatch) {
        // Save previous example if exists
        if (endpoint.currentExampleContent) {
          endpoint.successExamples.push({
            title: endpoint.currentExampleTitle || 'Example',
            content: endpoint.currentExampleContent
          });
        }
        // Start new example
        endpoint.currentExampleTitle = (successExampleMatch[1] || 'Example').replace(/:$/, '').trim();
        endpoint.currentExampleContent = '';
        currentSection = 'successExample';
        continue;
      }

      // @apiSuccess - with optional (Success XXX) or (Status XXX) status code
      // Matches: @apiSuccess {Type} field description
      // Or:      @apiSuccess (Success 202) {Type} field description
      // Or:      @apiSuccess (Status 201) {Type} field description
      const successMatch = line.match(/@apiSuccess\s*(?:\((?:Success|Status)\s+(\d+)\))?\s*(?:\{([^}]+)\})?\s*\[?([^\]\s]+)\]?(?:\s+(.+))?/);
      if (successMatch) {
        const statusCode = successMatch[1] ? parseInt(successMatch[1]) : null;
        // Track the highest non-200 status code for this endpoint
        if (statusCode && statusCode !== 200) {
          endpoint.successStatusCode = statusCode;
        }
        endpoint.success.push({
          type: successMatch[2] || 'string',
          field: successMatch[3],
          description: successMatch[4] || '',
          statusCode: statusCode
        });
        currentSection = null;
        continue;
      }

      // @apiErrorExample - MUST be checked before @apiError (since it contains @apiError)
      if (line.includes('@apiErrorExample')) {
        currentSection = 'errorExample';
        continue;
      }

      // @apiError
      const errorMatch = line.match(/@apiError\s+\{([^}]+)\}\s+(\S+)(?:\s+(.+))?/);
      if (errorMatch) {
        endpoint.error.push({
          type: errorMatch[1],
          field: errorMatch[2],
          description: errorMatch[3] || ''
        });
        currentSection = null;
        continue;
      }

      // Continue multiline descriptions
      if (currentSection === 'description' && !line.startsWith('@')) {
        endpoint.description += (line ? ' ' + line : '\n');
      } else if (currentSection === 'deprecated' && !line.startsWith('@')) {
        endpoint.deprecatedMessage += (line ? ' ' + line : '\n');
      } else if (currentSection === 'param' && !line.startsWith('@')) {
        const lastParam = endpoint.params[endpoint.params.length - 1];
        if (lastParam) {
          lastParam.description += (line ? '\n' + line : '\n');
        }
      } else if (currentSection === 'body' && !line.startsWith('@')) {
        const lastBody = endpoint.body[endpoint.body.length - 1];
        if (lastBody) {
          lastBody.description += (line ? '\n' + line : '\n');
        }
      } else if (currentSection === 'successExample' && line) {
        endpoint.currentExampleContent += line + '\n';
      } else if (currentSection === 'errorExample' && line) {
        endpoint.errorExample += line + '\n';
      }
    }

    // Save the last example if exists
    if (endpoint.currentExampleContent) {
      endpoint.successExamples.push({
        title: endpoint.currentExampleTitle || 'Example',
        content: endpoint.currentExampleContent
      });
    }

    // Only return if we found a valid API endpoint
    if (endpoint.method && endpoint.path) {
      return endpoint;
    }

    return null;
  }

  convertTypeToOpenAPI(apiDocType) {
    const typeMap = {
      'String': 'string',
      'Number': 'number',
      'Integer': 'integer',
      'Boolean': 'boolean',
      'Object': 'object',
      'Array': 'array',
      'Number[]': 'array',
      'String[]': 'array',
      'File': 'string'
    };

    const openApiType = typeMap[apiDocType] || 'string';

    // Handle arrays
    if (apiDocType.includes('[]')) {
      const itemType = apiDocType.replace('[]', '');
      return {
        type: 'array',
        items: {
          type: this.convertTypeToOpenAPI(itemType).type || 'string'
        }
      };
    }

    return { type: openApiType };
  }

  buildOpenAPIPath(endpoint) {
    const pathItem = {
      [endpoint.method]: {
        tags: [endpoint.group],
        summary: endpoint.summary || endpoint.name || 'API Endpoint',
        description: endpoint.description,
        operationId: endpoint.name,
        parameters: [],
        responses: {},
        'x-version': endpoint.version, // Store version as metadata
        'x-code-samples': this.generateCodeSamples(endpoint)
      }
    };

    // Add deprecated flag
    if (endpoint.deprecated) {
      pathItem[endpoint.method].deprecated = true;
      if (endpoint.deprecatedMessage) {
        pathItem[endpoint.method].description = endpoint.description +
          '\n\n**DEPRECATED:** ' + endpoint.deprecatedMessage;
      }
    }

    // Add permission as extension if present
    if (endpoint.permission) {
      pathItem[endpoint.method]['x-permission'] = endpoint.permission;
    }

    // Convert query parameters (with deduplication)
    const paramNames = new Set();
    endpoint.params.forEach(param => {
      // Skip duplicate parameters
      if (paramNames.has(param.name)) {
        console.warn(`  Warning: Duplicate parameter '${param.name}' in ${endpoint.method.toUpperCase()} ${endpoint.path}`);
        return;
      }
      paramNames.add(param.name);

      // Check if this parameter is in the URL path (e.g., :supplyid in /watersupply/:supplyid)
      const isPathParam = endpoint.path.includes(':' + param.name);

      const openApiParam = {
        name: param.name,
        in: isPathParam ? 'path' : 'query',
        description: param.description,
        required: isPathParam ? true : !param.optional,  // Path params are always required
        schema: this.convertTypeToOpenAPI(param.type)
      };

      if (param.defaultValue !== undefined) {
        openApiParam.schema.default = this.parseDefaultValue(param.defaultValue, openApiParam.schema.type);
      }

      pathItem[endpoint.method].parameters.push(openApiParam);
    });

    // Convert request body parameters
    if (endpoint.body.length > 0) {
      const bodyProperties = {};
      const requiredSet = new Set();

      endpoint.body.forEach(param => {
        // Skip duplicate body parameters
        if (bodyProperties[param.name]) {
          console.warn(`  Warning: Duplicate body parameter '${param.name}' in ${endpoint.method.toUpperCase()} ${endpoint.path}`);
          return;
        }

        bodyProperties[param.name] = {
          ...this.convertTypeToOpenAPI(param.type),
          description: param.description
        };

        if (param.defaultValue !== undefined) {
          bodyProperties[param.name].default = this.parseDefaultValue(
            param.defaultValue,
            bodyProperties[param.name].type
          );
        }

        if (!param.optional) {
          requiredSet.add(param.name);
        }
      });

      const required = Array.from(requiredSet);
      const requestBodySchema = {
        type: 'object',
        properties: bodyProperties
      };

      if (required.length > 0) {
        requestBodySchema.required = required;
      }

      pathItem[endpoint.method].requestBody = {
        required: required.length > 0,
        content: {
          'application/json': {
            schema: requestBodySchema
          }
        }
      };
    }

    // Build success response
    if (endpoint.success.length > 0) {
      const properties = {};

      endpoint.success.forEach(field => {
        const fieldPath = field.field.split('.');
        let current = properties;

        for (let i = 0; i < fieldPath.length - 1; i++) {
          const part = fieldPath[i];
          if (!current[part]) {
            current[part] = {
              type: 'object',
              properties: {}
            };
          }
          if (current[part].properties) {
            current = current[part].properties;
          } else if (current[part].items?.properties) {
            current = current[part].items.properties;
          } else {
            // Create properties if missing
            current[part].properties = {};
            current = current[part].properties;
          }
        }

        const fieldName = fieldPath[fieldPath.length - 1];
        const isOptional = field.field.startsWith('[');
        current[fieldName] = {
          ...this.convertTypeToOpenAPI(field.type),
          description: field.description
        };
      });

      // Use the status code from @apiSuccess (Success XXX) or default to 200
      const successCode = endpoint.successStatusCode || 200;
      pathItem[endpoint.method].responses[String(successCode)] = {
        description: 'Successful response',
        content: {
          'application/json': {
            schema: {
              type: 'object',
              properties: properties
            }
          }
        }
      };

      // Add examples if available
      if (endpoint.successExamples && endpoint.successExamples.length > 0) {
        const examples = {};

        for (const ex of endpoint.successExamples) {
          try {
            // Clean the example: remove comment asterisks
            let cleanExample = ex.content
              .replace(/^\s*\*\s?/gm, '')  // Remove leading * from comment lines
              .trim();

            // Extract the JSON object
            if (cleanExample.includes('{')) {
              const jsonStart = cleanExample.indexOf('{');
              let braceCount = 0;
              let jsonEnd = jsonStart;

              for (let i = jsonStart; i < cleanExample.length; i++) {
                if (cleanExample[i] === '{') braceCount++;
                if (cleanExample[i] === '}') braceCount--;
                if (braceCount === 0) {
                  jsonEnd = i + 1;
                  break;
                }
              }

              const jsonStr = cleanExample.substring(jsonStart, jsonEnd);
              if (jsonStr) {
                // Create a key from the title (camelCase, no spaces)
                const key = ex.title.replace(/[^a-zA-Z0-9]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
                examples[key] = {
                  summary: ex.title,
                  value: JSON.parse(jsonStr)
                };
              }
            }
          } catch (e) {
            // If parsing fails, skip this example silently
          }
        }

        // Add examples to response if we have any
        if (Object.keys(examples).length > 0) {
          pathItem[endpoint.method].responses[String(successCode)].content['application/json'].examples = examples;
        }
      }
    } else {
      // Default success response - use status code if specified, otherwise 200
      const successCode = endpoint.successStatusCode || 200;
      pathItem[endpoint.method].responses[String(successCode)] = {
        description: 'Successful response'
      };
    }

    // Add common error responses
    pathItem[endpoint.method].responses['400'] = {
      description: 'Bad Request'
    };
    pathItem[endpoint.method].responses['401'] = {
      description: 'Unauthorized'
    };
    pathItem[endpoint.method].responses['500'] = {
      description: 'Internal Server Error'
    };

    return pathItem;
  }

  parseDefaultValue(value, type) {
    if (type === 'number' || type === 'integer') {
      return parseInt(value, 10);
    }
    if (type === 'boolean') {
      return value === 'true';
    }
    return value;
  }

  generateCodeSamples(endpoint) {
    const samples = [];
    const path = endpoint.path;
    const method = endpoint.method.toUpperCase();

    // Build sample body for POST/PUT/PATCH
    let bodyExample = '';
    if (endpoint.body.length > 0) {
      const bodyObj = {};
      endpoint.body.forEach(param => {
        if (!param.optional) {
          bodyObj[param.name] = param.defaultValue || (param.type === 'String' ? 'value' : 0);
        }
      });
      bodyExample = JSON.stringify(bodyObj, null, 2);
    }

    // JavaScript (Fetch API)
    let jsCode = `fetch('${path}', {\n  method: '${method}',\n  headers: {\n    'apiKey': 'YOUR_API_KEY',\n    'accessToken': 'YOUR_ACCESS_TOKEN',\n    'Content-Type': 'application/json'\n  }`;
    if (bodyExample) {
      jsCode += `,\n  body: '${bodyExample.replace(/\n/g, '\\n')}'`;
    }
    jsCode += `\n})\n.then(response => response.json())\n.then(data => console.log(data))\n.catch(error => console.error('Error:', error));`;

    samples.push({
      lang: 'JavaScript',
      source: jsCode
    });

    // Python (requests)
    let pyCode = `import requests\n\nurl = '${path}'\nheaders = {\n    'apiKey': 'YOUR_API_KEY',\n    'accessToken': 'YOUR_ACCESS_TOKEN',\n    'Content-Type': 'application/json'\n}\n`;
    if (bodyExample) {
      pyCode += `data = ${bodyExample.replace(/"/g, "'")}\n\n`;
      pyCode += `response = requests.${method.toLowerCase()}(url, headers=headers, json=data)`;
    } else {
      pyCode += `\nresponse = requests.${method.toLowerCase()}(url, headers=headers)`;
    }
    pyCode += `\nprint(response.json())`;

    samples.push({
      lang: 'Python',
      source: pyCode
    });

    // C# (HttpClient)
    let csharpCode = `using System;\nusing System.Net.Http;\nusing System.Text;\nusing System.Threading.Tasks;\n\nclass Program {\n    static async Task Main() {\n        using var client = new HttpClient();\n        client.DefaultRequestHeaders.Add("apiKey", "YOUR_API_KEY");\n        client.DefaultRequestHeaders.Add("accessToken", "YOUR_ACCESS_TOKEN");\n        \n`;

    if (bodyExample) {
      csharpCode += `        var content = new StringContent(${JSON.stringify(bodyExample)}, Encoding.UTF8, "application/json");\n        var response = await client.${method === 'GET' ? 'Get' : 'Post'}Async("${path}", content);`;
    } else {
      csharpCode += `        var response = await client.${method === 'GET' ? 'Get' : method.charAt(0) + method.slice(1).toLowerCase()}Async("${path}");`;
    }

    csharpCode += `\n        string result = await response.Content.ReadAsStringAsync();\n        Console.WriteLine(result);\n    }\n}`;

    samples.push({
      lang: 'C#',
      source: csharpCode
    });

    return samples;
  }

  processDirectory(dir) {
    const files = fs.readdirSync(dir);

    files.forEach(file => {
      const filePath = path.join(dir, file);
      const stat = fs.statSync(filePath);

      if (stat.isDirectory()) {
        this.processDirectory(filePath);
      } else if (file.endsWith('.php')) {
        console.log(`Processing: ${filePath}`);
        const endpoints = this.parsePhpFile(filePath);

        // Group endpoints by version
        endpoints.forEach(endpoint => {
          // Extract version from path (e.g., /1.1/customer -> 1.1)
          const versionMatch = endpoint.path.match(/^\/([\d.]+)\//);
          const version = versionMatch ? versionMatch[1] : '1.0';

          this.versions.add(version);

          if (!this.endpointsByVersion[version]) {
            this.endpointsByVersion[version] = [];
          }
          this.endpointsByVersion[version].push(endpoint);
        });
      }
    });
  }

  /**
   * Compare two version strings (e.g., "1.0" vs "1.1")
   * Returns: negative if v1 < v2, positive if v1 > v2, 0 if equal
   */
  compareVersions(v1, v2) {
    // Normalize version strings (remove trailing .0, handle x.x.x format)
    const normalize = (v) => v.replace(/\.0+$/, '').split('.').map(Number);
    const parts1 = normalize(v1);
    const parts2 = normalize(v2);
    for (let i = 0; i < Math.max(parts1.length, parts2.length); i++) {
      const p1 = parts1[i] || 0;
      const p2 = parts2[i] || 0;
      if (p1 !== p2) return p1 - p2;
    }
    return 0;
  }

  /**
   * Check if an endpoint should be included in a specific version
   * An endpoint is included if its @apiSince <= target version
   * Endpoints without @apiSince are included in all versions
   */
  endpointExistsInVersion(endpoint, targetVersion) {
    if (!endpoint.since) return true; // No @apiSince = exists in all versions

    // Normalize version (e.g., "1.1.0" -> "1.1")
    const sinceVersion = endpoint.since.replace(/\.0+$/, '');
    return this.compareVersions(sinceVersion, targetVersion) <= 0;
  }

  /**
   * Build OpenAPI spec for a specific version
   * Older versions inherit from newer versions, with overrides for changed endpoints
   * Endpoints are excluded if their @apiVersion is higher than the target version
   */
  buildSpecForVersion(version) {
    const openapi = this.initOpenAPISpec(version);

    // Get sorted versions (newest first)
    const sortedVersions = Array.from(this.versions).sort((a, b) => {
      return this.compareVersions(b, a); // Descending order
    });

    const latestVersion = sortedVersions[0];
    const isLatest = version === latestVersion;

    // For older versions: start with latest endpoints, then override with version-specific
    // For latest version: use only its endpoints
    let endpointsToProcess = [];

    if (isLatest) {
      // Latest version uses only its own endpoints
      endpointsToProcess = this.endpointsByVersion[version] || [];
    } else {
      // Older versions: start with latest, override with version-specific
      const latestEndpoints = this.endpointsByVersion[latestVersion] || [];
      const versionEndpoints = this.endpointsByVersion[version] || [];

      // Create a map of version-specific endpoints by path+method
      const versionOverrides = new Map();
      versionEndpoints.forEach(endpoint => {
        let cleanPath = endpoint.path.replace(/^\/([\d.]+)/, '');
        const key = `${endpoint.method}:${cleanPath}`;
        versionOverrides.set(key, endpoint);
      });

      // Process latest endpoints, replacing with version-specific where available
      // But ONLY if the endpoint exists in this version (@apiVersion <= target version)
      latestEndpoints.forEach(endpoint => {
        let cleanPath = endpoint.path.replace(/^\/([\d.]+)/, '');
        const key = `${endpoint.method}:${cleanPath}`;

        if (versionOverrides.has(key)) {
          // Use the version-specific override (always include, it's explicitly for this version)
          endpointsToProcess.push(versionOverrides.get(key));
          versionOverrides.delete(key); // Mark as processed
        } else if (this.endpointExistsInVersion(endpoint, version)) {
          // Use the latest version's endpoint only if it existed in this version
          endpointsToProcess.push(endpoint);
        }
        // Skip endpoints that didn't exist in this version (introduced in later version)
      });

      // Add any version-specific endpoints not in latest (rare, but possible)
      versionOverrides.forEach(endpoint => {
        endpointsToProcess.push(endpoint);
      });
    }

    // Process all endpoints
    endpointsToProcess.forEach(endpoint => {
      const pathItem = this.buildOpenAPIPath(endpoint);

      // Collect schema from response fields
      this.collectSchemaFromEndpoint(endpoint);

      // Strip version from path (e.g., /1.1/customer -> /customer)
      let cleanPath = endpoint.path;
      if (cleanPath.match(/^\/([\d.]+)\//)) {
        cleanPath = cleanPath.replace(/^\/([\d.]+)/, '');
      }
      // Convert :paramname to {paramname} for OpenAPI format
      cleanPath = cleanPath.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, '{$1}');

      // Merge path if it already exists (different methods on same path)
      if (openapi.paths[cleanPath]) {
        openapi.paths[cleanPath] = {
          ...openapi.paths[cleanPath],
          ...pathItem
        };
      } else {
        openapi.paths[cleanPath] = pathItem;
      }

      // Add tag if not exists
      if (endpoint.group && !openapi.tags.find(t => t.name === endpoint.group)) {
        openapi.tags.push({
          name: endpoint.group,
          description: `${endpoint.group} endpoints`
        });
      }
    });

    // Sort tags alphabetically by name
    openapi.tags.sort((a, b) => a.name.localeCompare(b.name));

    return openapi;
  }

  generateRapiDocHtml(versions, specFiles) {
    // Load apidoc.json for display settings
    let apidocConfig = {};
    try {
      apidocConfig = JSON.parse(fs.readFileSync(this.config.apidocConfig, 'utf8'));
    } catch (e) {
      console.warn('Could not load apidoc.json for HTML generation, using defaults');
    }

    const apiTitle    = apidocConfig.name         || 'API Documentation';
    const primaryColor = apidocConfig.primaryColor || '#4CAF50';
    const theme       = apidocConfig.theme         || 'dark';
    const prefsKey    = apidocConfig.prefsKey      || 'app-api-prefs';

    const defaultVersion = versions[0]; // Latest version is first
    const versionOptions = versions.map(v =>
      `<option value="${specFiles[v]}" ${v === defaultVersion ? 'selected' : ''}>Version ${v}</option>`
    ).join('\n            ');

    const rapidocHTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>${apiTitle}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script type="module" src="https://unpkg.com/rapidoc@9.3.4/dist/rapidoc-min.js"></script>
  <style>
    body { margin: 0; padding: 0; }
    .top-bar {
      position: fixed;
      top: 10px;
      right: 10px;
      z-index: 1000;
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .version-selector {
      background: #333;
      color: white;
      padding: 8px 12px;
      border-radius: 4px;
      border: 1px solid ${primaryColor};
      font-family: "Open Sans", sans-serif;
      font-size: 14px;
      cursor: pointer;
    }
    .version-selector:focus {
      outline: none;
      border-color: ${primaryColor};
    }
    .old-docs-link, .download-link {
      background: ${primaryColor};
      color: white;
      padding: 8px 16px;
      border-radius: 4px;
      text-decoration: none;
      font-family: "Open Sans", sans-serif;
      font-size: 14px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }
    .old-docs-link:hover, .download-link:hover {
      opacity: 0.85;
    }
    .download-link {
      background: #2196F3;
    }
    .download-link:hover {
      background: #1976D2;
    }
  </style>
</head>
<body>
  <div class="top-bar">
    <select id="versionSelector" class="version-selector" onchange="changeVersion(this.value)">
      ${versionOptions}
    </select>
    <a id="downloadLink" href="../${specFiles[defaultVersion]}" class="download-link" download>Download OpenAPI</a>
    <a href="old/index.html" class="old-docs-link" target="_blank">Old apiDoc</a>
  </div>

  <rapi-doc
    id="rapidoc"
    spec-url="../${specFiles[defaultVersion]}"

    theme="${theme}"
    bg-color="#1a1a1a"
    text-color="#f0f0f0"
    primary-color="${primaryColor}"
    header-color="${primaryColor}"

    render-style="focused"
    layout="row"
    schema-style="table"
    default-schema-tab="example"
    response-area-height="400px"

    show-header="true"
    show-info="true"
    show-components="true"
    allow-authentication="true"
    allow-server-selection="true"
    allow-api-list-style-selection="true"
    allow-try="true"
    allow-spec-url-load="false"
    allow-spec-file-load="false"

    api-key-name="apiKey"
    api-key-location="header"
    api-key-value=""

    show-curl-before-try="true"
    request-panel="examples"
    schema-expand-level="3"
    schema-description-expanded="true"

    font-size="default"
    regular-font="Open Sans"
    mono-font="Monaco"
    use-path-in-nav-bar="false"
    nav-bg-color="#2d2d2d"
    nav-text-color="#f0f0f0"
    nav-hover-bg-color="#404040"
    nav-hover-text-color="#ffffff"
    nav-accent-color="${primaryColor}"
    nav-item-spacing="relaxed"

    fill-request-fields-with-example="true"
    persist-auth="true"
    update-route="true"
  >
    <div slot="overview">
      <h2>${apiTitle}</h2>
      <p>Interactive API documentation with code examples and testing capability.</p>
      <ul>
        <li><strong>Use the version selector</strong> (top right) to switch between API versions</li>
        <li>Select a server and add your credentials in the Authentication section</li>
        <li>Try endpoints interactively or copy code examples</li>
      </ul>
    </div>
  </rapi-doc>

  <script>
    // Clear old version preference so it always defaults to latest
    localStorage.removeItem('preferredApiVersion');

    const PREFS_KEY = '${prefsKey}';

    function savePrefs(update) {
      const prefs = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
      Object.assign(prefs, update);
      localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
    }

    function getPrefs() {
      return JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
    }

    function changeVersion(specFile) {
      const rapidoc = document.getElementById('rapidoc');
      const downloadLink = document.getElementById('downloadLink');
      rapidoc.setAttribute('spec-url', '../' + specFile);
      downloadLink.setAttribute('href', '../' + specFile);
    }

    // Wait for RapiDoc to be ready
    customElements.whenDefined('rapi-doc').then(() => {
      const rapidoc = document.getElementById('rapidoc');

      // Listen for server changes
      rapidoc.addEventListener('api-server-change', (e) => {
        if (e.detail?.selectedServer?.url) {
          savePrefs({ serverUrl: e.detail.selectedServer.url });
        }
      });

      // After spec loads, restore saved preferences
      rapidoc.addEventListener('spec-loaded', () => {
        setTimeout(() => {
          const prefs = getPrefs();

          // Restore server using RapiDoc's API method
          if (prefs.serverUrl && typeof rapidoc.setApiServer === 'function') {
            rapidoc.setApiServer(prefs.serverUrl);
          }

          // Restore auth values
          const root = rapidoc.shadowRoot;
          if (!root) return;

          if (prefs.apiKey || prefs.accessToken) {
            root.querySelectorAll('input').forEach(input => {
              const pname = (input.dataset.pname || '').toLowerCase();
              if (pname === 'apikey' && prefs.apiKey) {
                input.value = prefs.apiKey;
                input.dispatchEvent(new Event('change', { bubbles: true }));
              }
              if (pname === 'accesstoken' && prefs.accessToken) {
                input.value = prefs.accessToken;
                input.dispatchEvent(new Event('change', { bubbles: true }));
              }
            });
          }
        }, 100);
      });

      // Save auth values on blur (when user leaves an input field)
      rapidoc.addEventListener('focusout', (e) => {
        if (e.target.tagName !== 'INPUT') return;
        const input = e.target;
        const pname = (input.dataset.pname || '').toLowerCase();
        const parent = input.closest('[data-ptype]') || input.parentElement;
        const label = parent ? parent.textContent.toLowerCase() : '';

        if ((pname === 'apikey' || label.includes('apikey')) && input.value) {
          savePrefs({ apiKey: input.value });
        }
        if ((pname === 'accesstoken' || label.includes('accesstoken')) && input.value) {
          savePrefs({ accessToken: input.value });
        }
      }, true);
    });
  </script>
</body>
</html>`;

    return rapidocHTML;
  }

  /**
   * Standard types that should NOT become schemas
   */
  isStandardType(typeName) {
    const standardTypes = [
      'string', 'number', 'integer', 'boolean', 'object', 'array',
      'String', 'Number', 'Integer', 'Boolean', 'Object', 'Array',
      'String[]', 'Number[]', 'Integer[]', 'Boolean[]',
      'File', 'Date', 'DateTime',
      'JSON', 'Json', 'json' // JSON is a format, not an entity
    ];
    return standardTypes.includes(typeName);
  }

  /**
   * Normalize entity type name to consistent casing
   * e.g., "WaterSupply" -> "Watersupply" (matches group names)
   */
  normalizeEntityName(name) {
    // Convert to lowercase then capitalize first letter
    // This matches how apiDoc group names are typically formatted
    return name.charAt(0).toUpperCase() + name.slice(1).toLowerCase();
  }

  /**
   * Extract entity type from apiDoc type notation
   * e.g., "Device[]" -> "Device", "Customer" -> "Customer"
   * Returns normalized name for consistency
   */
  extractEntityType(typeString) {
    // Remove array notation
    const baseType = typeString.replace('[]', '');

    // Check if it's a custom entity type (not standard)
    if (!this.isStandardType(baseType) && /^[A-Z]/.test(baseType)) {
      // Normalize to consistent casing to avoid duplicates like WaterSupply vs Watersupply
      return this.normalizeEntityName(baseType);
    }
    return null;
  }

  /**
   * Collect entity schemas from endpoint's success fields
   * Dynamically builds schema properties from nested fields like data.deviceid, data.name
   */
  collectSchemaFromEndpoint(endpoint) {
    if (!endpoint.success || endpoint.success.length === 0) return;

    // First pass: find entity type declarations (e.g., {Device} data, {Customer[]} customers)
    const entityFields = {};
    endpoint.success.forEach(field => {
      const entityType = this.extractEntityType(field.type);
      if (entityType) {
        // Store the field name that contains this entity type
        // e.g., "data" for "{Device} data" or "customers" for "{Customer[]} customers"
        entityFields[field.field] = entityType;

        // Initialize schema if not exists
        if (!this.collectedSchemas[entityType]) {
          this.collectedSchemas[entityType] = {
            type: 'object',
            description: `${entityType} entity`,
            properties: {}
          };
        }
      }
    });

    // Second pass: collect properties for each entity from nested fields
    // e.g., "data.deviceid" -> deviceid property of Device schema
    endpoint.success.forEach(field => {
      if (!field.field) return; // Skip fields without names
      const parts = field.field.split('.');
      if (parts.length >= 2) {
        const parentField = parts[0];
        const entityType = entityFields[parentField];

        if (entityType && parts.length === 2) {
          // Direct property of the entity (e.g., data.deviceid)
          const propName = parts[1];
          const schema = this.collectedSchemas[entityType];

          // Only add if not already defined (first occurrence wins)
          if (!schema.properties[propName]) {
            const propSchema = this.convertTypeToOpenAPI(field.type);
            if (field.description) {
              propSchema.description = field.description;
            }
            schema.properties[propName] = propSchema;
          }
        }
      }
    });
  }

  /**
   * Load custom schemas from external JSON file
   */
  loadCustomSchemas() {
    if (!this.config.customOverrides) return {};

    try {
      if (fs.existsSync(this.config.customOverrides)) {
        const customData = JSON.parse(fs.readFileSync(this.config.customOverrides, 'utf8'));
        console.log(`Loaded overrides from: ${this.config.customOverrides}`);
        return customData;
      }
    } catch (e) {
      console.warn(`Could not load overrides: ${e.message}`);
    }

    return {};
  }

  /**
   * Merge collected schemas with custom schemas and add to OpenAPI spec
   */
  finalizeSchemas(openapi) {
    const customData = this.loadCustomSchemas();

    // Start with auto-generated schemas
    const finalSchemas = { ...this.collectedSchemas };

    // Merge custom schemas (custom overrides auto-generated)
    if (customData.schemas) {
      Object.keys(customData.schemas).forEach(name => {
        finalSchemas[name] = customData.schemas[name];
      });
    }

    // Add common/reusable schemas
    this.addCommonSchemas(finalSchemas);

    // Sort schemas alphabetically
    const sortedSchemas = {};
    Object.keys(finalSchemas).sort().forEach(key => {
      sortedSchemas[key] = finalSchemas[key];
    });

    openapi.components.schemas = sortedSchemas;

    // Also merge any custom components (parameters, responses, etc.)
    if (customData.parameters) {
      openapi.components.parameters = {
        ...openapi.components.parameters,
        ...customData.parameters
      };
    }

    if (customData.responses) {
      openapi.components.responses = customData.responses;
    }

    // Merge any top-level overrides (info, servers, etc.)
    if (customData.info) {
      openapi.info = { ...openapi.info, ...customData.info };
    }

    if (customData.tags) {
      // Add custom tags that don't exist
      customData.tags.forEach(tag => {
        if (!openapi.tags.find(t => t.name === tag.name)) {
          openapi.tags.push(tag);
        } else {
          // Update existing tag description
          const existing = openapi.tags.find(t => t.name === tag.name);
          Object.assign(existing, tag);
        }
      });
    }
  }

  /**
   * Add common reusable schemas
   */
  addCommonSchemas(schemas) {
    // Pagination schema
    schemas['Pagination'] = {
      type: 'object',
      description: 'Pagination information',
      properties: {
        currentpage: { type: 'integer', description: 'Current page number' },
        totalpages: { type: 'integer', description: 'Total number of pages' },
        totalrecords: { type: 'integer', description: 'Total number of records' },
        limit: { type: 'integer', description: 'Records per page' }
      }
    };

    // Error response schema
    schemas['ErrorResponse'] = {
      type: 'object',
      description: 'Standard error response',
      properties: {
        error: { type: 'boolean', example: true },
        message: { type: 'string', description: 'Error message' },
        code: { type: 'integer', description: 'Error code' }
      },
      required: ['error', 'message']
    };

    // Success response schema
    schemas['SuccessResponse'] = {
      type: 'object',
      description: 'Standard success response',
      properties: {
        success: { type: 'boolean', example: true },
        message: { type: 'string', description: 'Success message' }
      }
    };
  }

  convert() {
    console.log('Starting apiDoc to OpenAPI conversion...\n');

    if (!fs.existsSync(this.config.sourceDir)) {
      console.error(`Source directory not found: ${this.config.sourceDir}`);
      process.exit(1);
    }

    // Phase 1: Process all files and group endpoints by version
    this.processDirectory(this.config.sourceDir);

    // Sort versions (newest first)
    const sortedVersions = Array.from(this.versions).sort((a, b) => {
      const partsA = a.split('.').map(Number);
      const partsB = b.split('.').map(Number);
      for (let i = 0; i < Math.max(partsA.length, partsB.length); i++) {
        const diff = (partsB[i] || 0) - (partsA[i] || 0);
        if (diff !== 0) return diff;
      }
      return 0;
    });

    console.log(`\nFound API versions: ${sortedVersions.join(', ')}`);

    const outputDir = path.dirname(this.config.outputFile);
    if (!fs.existsSync(outputDir)) {
      fs.mkdirSync(outputDir, { recursive: true });
    }

    // Phase 2: Generate OpenAPI spec for each version
    const specFiles = {};
    let totalEndpoints = 0;

    sortedVersions.forEach(version => {
      this.collectedSchemas = {}; // Reset schemas for each version
      const openapi = this.buildSpecForVersion(version);

      // Finalize schemas for this version
      this.finalizeSchemas(openapi);

      // Sort paths alphabetically
      const sortedPaths = {};
      Object.keys(openapi.paths).sort().forEach(key => {
        sortedPaths[key] = openapi.paths[key];
      });
      openapi.paths = sortedPaths;

      // Write version-specific OpenAPI JSON
      const versionFile = this.config.outputFile.replace('.json', `-v${version}.json`);
      fs.writeFileSync(versionFile, JSON.stringify(openapi, null, 2), 'utf8');

      specFiles[version] = path.basename(versionFile);
      const endpointCount = Object.keys(openapi.paths).length;
      totalEndpoints += endpointCount;
      console.log(`  v${version}: ${endpointCount} endpoints -> ${versionFile}`);
    });

    // Also write a "latest" version as the default openapi.json
    const latestVersion = sortedVersions[0];
    this.collectedSchemas = {};
    const latestSpec = this.buildSpecForVersion(latestVersion);
    this.finalizeSchemas(latestSpec);
    const sortedPaths = {};
    Object.keys(latestSpec.paths).sort().forEach(key => {
      sortedPaths[key] = latestSpec.paths[key];
    });
    latestSpec.paths = sortedPaths;
    fs.writeFileSync(this.config.outputFile, JSON.stringify(latestSpec, null, 2), 'utf8');

    // Write RapiDoc HTML with version selector
    if (this.config.swaggerHtmlDir) {
      if (!fs.existsSync(this.config.swaggerHtmlDir)) {
        fs.mkdirSync(this.config.swaggerHtmlDir, { recursive: true });
      }

      const htmlPath = path.join(this.config.swaggerHtmlDir, 'index.html');
      fs.writeFileSync(htmlPath, this.generateRapiDocHtml(sortedVersions, specFiles), 'utf8');
      console.log(`\nRapiDoc UI written to: ${htmlPath}`);
    }

    console.log(`\nConversion complete!`);
    console.log(`Total endpoints across all versions: ${totalEndpoints}`);
    console.log(`Total tags: ${latestSpec.tags.length}`);
  }
}

// Run converter
const converter = new ApiDocToOpenAPIConverter(CONFIG);
converter.convert();
