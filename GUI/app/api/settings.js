// @flow
import Store from 'electron-store';
import { api } from 'electron-util';
import fs from 'fs-extra';
import axios from 'axios';
import configSchema from '../constants/config-schema.json';
// eslint-disable-next-line import/no-cycle
import Docker from './docker';
import Utils from './utils';

export type ConfigObjectType = {
  +configured: boolean,
  +local: boolean,
  +apiProtocol: 'http' | 'https',
  +apiHostname: string,
  +apiPort: number,
  +apiPath: string,
  +dataPath: string,
  +containerName: string,
  +apiKey: string,
  +dockerExecutablePath: string
};

export default {
  configStore: new Store({ schema: configSchema }),
  getConfig(): ConfigObjectType {
    return {
      configured: this.configStore.get('configured'),
      local: this.configStore.get('local'),
      apiProtocol: this.configStore.get('apiProtocol'),
      apiHostname: this.configStore.get('apiHostname'),
      apiPort: this.configStore.get('apiPort'),
      apiPath: this.configStore.get('apiPath'),
      dataPath: this.configStore.get(
        'dataPath',
        `${api.app.getPath('home')}/.RNADetector`
      ),
      dockerExecutablePath: this.configStore.get('dockerExecutablePath'),
      containerName: this.configStore.get('containerName'),
      apiKey: this.configStore.get('apiKey')
    };
  },
  getApiUrl(config: ConfigObjectType = this.getConfig()): string {
    const path = config.apiPath.replace(/^\/|\/$/gm, '');
    return `${config.apiProtocol}://${config.apiHostname}:${config.apiPort}/${path}/`;
  },
  saveConfig(config: ConfigObjectType): Promise<ConfigObjectType> {
    // eslint-disable-next-line no-async-promise-executor
    return new Promise(async (resolve, reject) => {
      try {
        const oldConfig = this.getConfig();
        const newConfig = {
          ...oldConfig,
          ...config,
          configured: true
        };
        if (oldConfig.containerName !== newConfig.containerName) {
          Docker.removeContainer(oldConfig);
        }
        if (oldConfig.configured && oldConfig.dataPath !== newConfig.dataPath) {
          // TODO
        }
        if (newConfig.local && !newConfig.apiKey) {
          newConfig.apiKey = await Docker.generateAuthToken();
        }
        await this.checkConfig(newConfig);
        this.configStore.set(newConfig);
        resolve(newConfig);
      } catch (e) {
        reject(e.message);
      }
    });
  },
  async checkUrl(config: ConfigObjectType) {
    const {
      data: { data }
    } = await axios.get(`${this.getApiUrl(config)}ping`);
    if (data !== 'pong') throw new Error('Invalid webservice URL');
  },
  async checkToken(config: ConfigObjectType) {
    let data = null;
    try {
      const response = await axios.get(`${this.getApiUrl(config)}auth-ping`, {
        headers: {
          Authorization: `Bearer ${config.apiKey}`
        }
      });
      data = response.data.data;
    } catch (e) {
      throw new Error(`Invalid authentication token - ${e.message}`);
    }
    if (data !== 'pong') throw new Error('Invalid authentication token');
  },
  async checkConfig(config: ConfigObjectType = this.getConfig()) {
    if (config.local) {
      if (!(await fs.pathExists(config.dataPath))) {
        await fs.ensureDir(config.dataPath, 0o755);
      }
      await Docker.checkDockerProcess(config);
      const status = await Docker.checkContainerStatus(config);
      if (status !== 'running') {
        await Docker.startContainer(config);
      }
    }
    await this.checkUrl(config);
    await this.checkToken(config);
  }
};